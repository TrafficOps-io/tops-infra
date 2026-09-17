# tops-infra

Laravel integrations for Cloudflare accounts, DNS and Laravel Cloud domains.

One Composer package, `trafficops/tops-infra`, with focused modules in `packages/`.
The modules share releases and dependency resolution; no repository splitting or sibling path repositories are needed.

| Module | Documentation |
| --- | --- |
| `cloudflare` | [Usage and API](packages/cloudflare/README.md) |
| `laravel-cloud` | [Usage and API](packages/laravel-cloud/README.md) |

## Install

Requires PHP 8.4 or later and Laravel 12.

```sh
composer require trafficops/tops-infra
```

Laravel discovers the included service providers automatically. Read each module’s configuration and migration instructions before using it in an application.

## Development

Run commands from the repository root:

```sh
composer install
composer check
composer build
```

`composer check` validates the manifest, checks PHP formatting and runs every module’s PHPUnit suite.
`composer format` applies the formatting rules. `composer build` writes the distributable ZIP to `dist/`.
The committed lock file makes contributor and CI installs reproducible; consumers resolve their own dependency set.


## Releases and Packagist

The initial release line is `v0.1.0`; versions come from Git tags, not a hard-coded Composer version.

1. Publish this repository at [trafficops-io/tops-infra](https://github.com/trafficops-io/tops-infra).
2. A maintainer must [submit the repository to Packagist](https://packagist.org/packages/submit) once and grant the publishing account access. Registration cannot be replaced by an update request.
3. Add GitHub Actions secrets `PACKAGIST_USERNAME` and `PACKAGIST_TOKEN`. The Packagist **safe API token** is sufficient for updates. Enable GitHub private vulnerability reporting in the repository settings.
4. Push a semantic version tag, for example `v0.1.0`. The release workflow reruns CI on that exact tag for PHP 8.4 and 8.5, builds the archive, then requests a Packagist refresh and publishes a GitHub release with the ZIP attached.

The release job fails if credentials are missing or Packagist rejects the request. PHP packages are indexed from this repository by Packagist; there is no archive upload to Packagist.
See the [Packagist update API](https://packagist.org/apidoc#update-package) for the supported authentication and endpoint.

## Contributing and security

See [CONTRIBUTING.md](CONTRIBUTING.md), [SECURITY.md](SECURITY.md) and the [MIT license](LICENSE).
