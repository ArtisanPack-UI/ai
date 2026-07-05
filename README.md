# ArtisanPack UI AI

Shared AI foundation for the ArtisanPack UI ecosystem. Sits alongside `artisanpack-ui/core` and `artisanpack-ui/hooks` as a shared layer that other ArtisanPack UI packages can optionally depend on.

Built on top of [`laravel/ai`](https://github.com/laravel/ai).

See the [AI RFC](https://github.com/ArtisanPack-UI/.github/discussions/8) for design context and the roadmap for downstream feature work.

## Installation

```bash
composer require artisanpack-ui/ai
```

Publish the config:

```bash
php artisan vendor:publish --tag=artisanpack-package-config
```

## Usage

Access the shared AI foundation via the facade or helper:

```php
use ArtisanPackUI\Ai\Facades\Ai;

Ai::/* ... */;

// or
ai()->/* ... */;
```

## Contributing

As an open source project, this package is open to contributions from anyone. Please [read through the contributing guidelines](CONTRIBUTING.md) to learn more about how you can contribute.
