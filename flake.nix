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
          vendor/bin/drush --root web status
        '';
      in rec {
        default = pkgs.mkShell {
          buildInputs = with pkgs; [
            php81

            phpPackages.composer

            setupDrupal
          ];
        };
      }
    );

    formatter = ww-utils.lib.forEachWunderwerkSystem (
      system:
        nixpkgs.legacyPackages.${system}.alejandra
    );
  };
}
