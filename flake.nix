{
  inputs = {
    nixpkgs.url = "github:nixos/nixpkgs/nixos-23.05";
    ww-utils.url = "github:wunderwerkio/nix-ww-utils";
    flake-utils.url = "github:numtide/flake-utils";
  };

  outputs = {
    self,
    nixpkgs,
    ww-utils,
    flake-utils,
  }: flake-utils.lib.eachDefaultSystem (system:
    let
      overlays = [];
      pkgs = import nixpkgs {
        inherit system overlays;
      };
      # Embedded drupalspoons setup script.
      setupDrupal = pkgs.writeShellScriptBin "setup-drupal" ''
        composer init --no-interaction --quiet --name=drupalspoons/template --stability=dev

        composer config allow-plugins.composer/installers true
        composer config allow-plugins.cweagans/composer-patches true
        composer config allow-plugins.dealerdirect/phpcodesniffer-composer-installer true
        composer config allow-plugins.drupal/core-composer-scaffold true
        composer config allow-plugins.drupalspoons/composer-plugin true
        composer config allow-plugins.phpstan/extension-installer true

        # Accept a constraint for composer-plugin.
        echo -e "\n\n\nInstalling composer-plugin"
        composer require --dev --no-interaction drupalspoons/composer-plugin:$COMPOSER_PLUGIN_CONSTRAINT

        echo -e "\n\n\nPreparing $COMPOSER"
        composer drupalspoons:composer-json

        if [[ -z "$COMPOSER_PLUGIN_PREPARE" ]] || [[ "$COMPOSER_PLUGIN_PREPARE" != "true" ]] ; then
          echo -e "\nConfiguring project codebase for local tests"
          composer drupalspoons:configure
        fi

        echo -e "\nInstalling dependencies"
        composer update --prefer-stable --no-interaction --no-progress
        echo -e "\nConditionally installing Prophecy"
        composer drupalspoons:prophecy
      '';
      dockerImage = pkgs.dockerTools.buildImage {
        name = "wunderwerk/drupalci";
        tag = "php81";

        copyToRoot = pkgs.buildEnv {
          name = "image-root";
          paths = with pkgs; [
            php81
            phpPackages.composer
            setupDrupal
            bash
            coreutils
          ];
          pathsToLink = ["/bin"];
        };

        runAsRoot = "mkdir -p /usr/bin && ln -s ${pkgs.coreutils}/bin/env /usr/bin/env";

        extraCommands = ''
          echo "DRUPAL_CORE_CONSTRAINT=^10" > .composer-plugin.env
          echo "COMPOSER_PLUGIN_CONSTRAINT=^2" >> .composer-plugin.env
        '';

        config = {
          Cmd = [ "${pkgs.bash}/bin/bash" ];
          Env = [
            "DRUPAL_CORE_CONSTRAINT=^10"
            "COMPOSER_PLUGIN_CONSTRAINT=^2"
            "COMPOSER=composer.spoons.json"
            "COMPOSER_CACHE_DIR=/tmp/composer-cache"
            "WEB_ROOT=web"
            "NONINTERACTIVE=1"
            "COMPOSER_NO_INTERACTION=1"
            "WEB_PORT=9000"
            "SIMPLETEST_BASE_URL=http://localhost"
            "SIMPLETEST_DB=sqlite://localhost/sites/default/files/.sqlite"
          ];
        };
      };
    in {
      devShells.default = pkgs.mkShell {
        buildInputs = with pkgs; [
          php81

          phpPackages.composer

          setupDrupal
        ];

        shellHook = ''
          export DRUPAL_CORE_CONSTRAINT="^10"
          export COMPOSER_PLUGIN_CONSTRAINT="^2"
          export COMPOSER="composer.spoons.json"
          export COMPOSER_CACHE_DIR="/tmp/composer-cache"
          export WEB_ROOT="web"
          export NONINTERACTIVE="1"
          export COMPOSER_NO_INTERACTION="1"
          export WEB_PORT="9000"
          export SIMPLETEST_BASE_URL="http://localhost"
          export SIMPLETEST_DB="sqlite://localhost/sites/default/files/.sqlite"
          export PATH="$PATH:$(pwd)/vendor/bin"

          echo "DRUPAL_CORE_CONSTRAINT=^10" > .composer-plugin.env
          echo "COMPOSER_PLUGIN_CONSTRAINT=^2" >> .composer-plugin.env

          echo ""
          echo "> Setup dependencies and drupal"
          echo "> \$ setup-drupal"
          echo ">"
          echo "> Run PHPCS"
          echo "> \$ composer phpcs"
          echo ">"
          echo "> Run PHPUnit"
          echo "> \$ composer unit"
        '';
      };

      defaultPackage = dockerImage;

      formatter = pkgs.alejandra;
    }
  );
}
