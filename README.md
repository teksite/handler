
# Handler Package

## About
A lightweight Laravel package designed to simplify common PHP and Laravel operations, including try-catch handling, database querying, and other utility functions.
## Table of Contents
- [About](#about)
- [Author](#author)
- [Contact](#contact)
- [Installation](#installation)
- [Features](#features)
- [Usage](#usage)
- [Support](#support)
- 
## About
The **Handler Package** streamlines development by providing helper functions for managing try-catch blocks, fetching data from databases, and other common tasks in PHP and Laravel applications. It aims to reduce boilerplate code and improve code readability.


## Author
Developed by **Sina Zangiband**.

## Contact
- Website: [teksite.net](https://teksite.net)
- Email: support@teksite.net

---

## Installation

### Step 1: Install via Composer
Run the following command in your terminal:

```bash
composer require teksite/handler
```

### Step 2: Register the Service Provider

#### For Laravel 10 and 11
Add the service provider to the `bootstrap/providers.php` file:

```php
'providers' => [
    // Other Service Providers
    Teksite\Handler\ServiceProvider::class,
],
```

> **Note**: Laravel 5.5 and above supports auto-discovery, so this step is not required for newer versions.

```php
'providers' => [
    // Other Service Providers
    Teksite\Handler\ServiceProvider::class,
];
```
## Features
- Simplify exception handling.
```php
\Teksite\Handler\Actions\ServiceWrapper
```
example
```php
 return app(ServiceWrapper::class)(function () use ($inputs, $post) {
       $post->update(Arr::except($inputs, ['tag', 'meta', 'seo']));
       return $post;
 });
```

- Streamlined methods for querying and fetching data.
```php
\Teksite\Handler\Services\FetchDataService
```
example
```php
  public function get(mixed $fetchData = [])
  {
      return app(ServiceWrapper::class)(function () use ($fetchData) {
          return app(FetchDataService::class)(Post::class, ['title'], ...$fetchData);
      });
  }
```

### Configuration
The package includes a configuration file for customization. Publish it using:

```bash
php artisan vendor:publish --provider="Teksite\Handler\ServiceProvider"
```

Edit the configuration in `config/handler-settings.php` to adjust settings like default query limits or error logging.

## Support
For questions, issues, or feature requests, please reach out via:

- **Website**: [teksite.net](https://teksite.net)
- **Email**: support@teksite.net
- **GitHub Issues**: [teksite/handler](https://github.com/teações/extralaravel)

Contributions are welcome! Feel free to submit a pull request or open an issue on GitHub.
