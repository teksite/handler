# Handler Package

## About

A lightweight Laravel package designed to simplify common PHP and Laravel operations, including try-catch handling,
database querying, and other utility functions.

## Table of Contents

- [About](#about)
- [Author](#author)
- [Contact](#contact)
- [Installation](#installation)
- [Features](#features)
- [Support](#support)

## About

The **Handler Package** streamlines development by providing helper functions for managing try-catch blocks, fetching
data from databases, and other common tasks in PHP and Laravel applications. It aims to reduce boilerplate code and
improve code readability.

## Author

Developed by **Sina Zangiband**.

## Contact

- Website:
    - [teksite.net](https://teksite.net)
    - [laratek.net](https://laratek.net)
- Email: support@teksite.net

---

## Installation

### Step 1: Install via Composer

Run the following command in your terminal:

```bash
composer require teksite/handler
```

### Step 2: Register the Service Provider

#### For Laravel > 9

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

### Simplify exception handling.

```php
\Teksite\Handler\Actions\ServiceWrapper
```

example:

```php

 return ServiceWrapper::make()->do(function (){
    // your code
 })->ifFailed(function(){
    //in case your code failed
 })->run();

```

- in this code :
    - `do()` is necessary, and it is your main code to be processed in try-catch
    - `ifFailed` is run if your code fails, the error is log in laravel.log
    - you can ignore error handling by setting `$withHandler false` : `ServiceWrapper::make(withHandler: false)`
    - by default db transaction is active to set it off : `ServiceWrapper::make(hasTransaction: false)`
    - also by default the output of the `do` and is `ifFailed` is instance of `ServiceResult` to have integrated result.
      set it off by `ServiceWrapper::make(wrapServiceResult: false)`
    - all these configs can be set globally in `handler-settings.transaction` config file

### Streamlined methods for querying and fetching data.

```php
\Teksite\Handler\Services\FetchDataService
```

example of ServiceWrapper and FetchDataService

```php
 //
 public function get(mixed $fetchData = []){
    ServiceWrapper::make()->do(function () use ($fetchData){
        FetchDataService::get(Post::class, ['title'], ...$fetchData);
    })->ifFailed(function(){
    //in case your code failed
    })->run();
 });
}


```

- in this code:

    - you can only use `FetchDataService::get(Post::class, ['title'], ...$fetchData)` without ServiceWrapper class.
    - the arguments of this method are:
        - `string|Closure|Builder|Relation $model`: to get query from class or relation or model,`
        - `string|array|Closure|null $searchColumns`: search in column, to implement operators you can
          `$searchColumns = [['column'=>'title' , 'operator'=>'LIKE' ] , ['category'=>'=']`,
        - `array  $only = ['*']` : select desired columns,
        - `null|int|false $perPage = null` : number of records per page (can be changed in handler-settings.pagination
          config file),
        - `null|false|int  $limitPagination = null` : it is used to limit client to get large amount records per page (
          can be change in handler-settings.limit-pagination config file)

### Response by json or http

```
    // sucess message
    $response =ResponderServices::success('weldone' ,['post=>$post] , 201);
    
    // failed message
    $response =ResponderServices::failed('something went wrong' , ['auth'=> 'forbidden' , ...] ,500);

    // to redirct client as http response 
    $response->go();
    
    // to respone as json in api and ajax senario
    $response->reoly()

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
