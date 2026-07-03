<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## Installation Back-end Liquid8 (Untuk Testing di Local)

1. Clone project
```sh
git clone https://github.com/NRayaa/BackEnd-Liquid8.git
```

2. Open folder then use command prompt "powerShell, gitbash, etc". Install the dependencies and devDependencies.

```sh
composer install
```

3. Copy .env.example file to .env on the root folder. If using command prompt Windows you can type  
```sh
copy .env.example .env
```

4. Open file .env and setup your own database. Then scroll to this line ...
```sh
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=(database name)
DB_USERNAME=(database username)
DB_PASSWORD=
```

5. Open command prompt again. Run
```sh
php artisan key: generate
```

6. Run (for migrate design database => in folder migration)
```sh
php artisan migrate
```

7. Run (starting builtin server for testing)
```sh
php artisan serve
```
