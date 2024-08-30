let mix = require('laravel-mix');

mix
  .js('resources/blade-app/app.js', 'blade-app')
  .sass('resources/sass/app.scss', 'blade-app')
  .version();