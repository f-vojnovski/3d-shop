## Running the application locally

### Client

Run `npm install` in the 3d-shop-client directory, then run `npm start`.

The client app should now be running.

### API
Run `composer install` in the 3d-shop-api directory

Create a **.env** file in the 3d-shop-api direcotry

Copy everything from the example env to your newly created .env file

Add 

`SESSION_DOMAIN=localhost`

`SANCTUM_STATEFUL_DOMAINS=localhost`

to your .env file

#### These steps show how to connect to an sqlite database:
Add

`DB_CONNECTION=sqlite`

`DB_HOST=127.0.0.1`

`DB_PORT=3306`

to your .env file

Create a **database.sqlite** file in the 3d-shop-api/database directory

#### After configuring database

Run `php artisan migrate`

Run `php artisan key:generate`

Run `php artisan storage:link`

#### After all configuration is complete

Run `php artisan serve`
