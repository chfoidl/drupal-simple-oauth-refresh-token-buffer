{
  inputs = {
    nixpkgs.url = "github:nixos/nixpkgs/nixos-23.05";
    ww-utils.url = "github:wunderwerkio/nix-ww-utils";
  };

  outputs = {
    self,
    nixpkgs,
    ww-utils,
  }: {
    devShells = ww-utils.lib.forEachWunderwerkSystem (
      system: let
        overlays = [];
        pkgs = import nixpkgs {
          inherit system overlays;
        };
        setupDrupal = pkgs.writeShellScriptBin "setup-drupal" ''
          rm .composer-plugin.env composer.spoons.json composer.spoons.lock || true
          bash <(curl -s https://gitlab.com/drupalspoons/composer-plugin/-/raw/master/bin/setup)
          drush --root web status
        '';
      in rec {
        default = pkgs.mkShell {
          buildInputs = with pkgs; [
            php81

            phpPackages.composer

            setupDrupal
          ];

          shellHook = ''
            DRUPAL_CORE_CONSTRAINT="^10"
            COMPOSER_PLUGIN_CONSTRAINT="^2"
            COMPOSER="composer.spoons.json"
            WEB_ROOT="web"
            NONINTERACTIVE="1"
            COMPOSER_NO_INTERACTION="1"
            WEB_PORT="9000"
            SIMPLETEST_BASE_URL="http://localhost"
            SIMPLETEST_DB="sqlite://localhost/sites/default/files/.sqlite"

            PATH="$PATH:$(pwd)/vendor/bin"
          '';
        };
      }
    );

    formatter = ww-utils.lib.forEachWunderwerkSystem (
      system:
        nixpkgs.legacyPackages.${system}.alejandra
    );
  };
}
