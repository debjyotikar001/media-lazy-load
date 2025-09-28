<?php
/*
 * This file is part of MediaLazyLoad.
 *
 * (c) 2024 Debjyoti Kar <debjyotikar001@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

return [
  /*
  |--------------------------------------------------------------------------
  | Enable Media Lazy Loading
  |--------------------------------------------------------------------------
  |
  | This option allows you to enable or disable lazy loading functionality
  | for media in your application. If set to true, lazy loading will be
  | active. If set to false, media will load normally without lazy loading.
  |
  | Default: true
  |
  */
  'enabled' => env('MEDLAZYLOAD_ENABLED', true),

  /*
  |--------------------------------------------------------------------------
  | Allowed Environments
  |--------------------------------------------------------------------------
  |
  | Define the environments where the lazy loading are enabled. You may
  | disable it in specific environments such as during local development or
  | testing to simplify debugging. Values must be in a comma (,) separated
  | string.
  |
  | Default: local,production,staging
  |
  */
  'allowed_envs' => env('MEDLAZYLOAD_ALLOWED_ENVS', 'local,production,staging'),

  /*
  |--------------------------------------------------------------------------
  | Skip or Ignore Routes Urls
  |--------------------------------------------------------------------------
  |
  | Here you can specify routes urls paths, which you don't want to optimise.
  | You can use '*' as wildcard.
  |
  */
  'skip_urls' => [
    // '/',
    // 'about',
    // 'user/*',
    // '*_dashboard',
    // '*/download/*',
  ],

  /*
  |--------------------------------------------------------------------------
  | LazyHtml: Excluded User Agents
  |--------------------------------------------------------------------------
  |
  | Here you may define a list of User Agents that should be excluded
  | from lazy loading. If the current request's User Agent matches
  | any in this list, the <template> wrappers will be removed
  | and the HTML will render normally.
  |
  | Example: ['Googlebot', 'Bingbot', 'Slurp']
  |
  */
  'excluded_user_agents' => [
    // 'Googlebot',
    // 'Bingbot',
  ],

  /*
  |--------------------------------------------------------------------------
  | Root Margin
  |--------------------------------------------------------------------------
  |
  | Here you can specify when to start loading the elements before or after
  | they enter the viewport.
  | Format: 'top right bottom left'
  |
  | Default: '0px 0px 100px 0px'
  |
  */
  'rootMargin' => env('MEDLAZYLOAD_ROOTMARGIN', '0px 0px 100px 0px'),

  /*
  |--------------------------------------------------------------------------
  | Threshold
  |--------------------------------------------------------------------------
  |
  | Here you can defines how much of the element is visible before lazy
  | loading is triggered. The value can range from 0 to 1.
  |
  | Default: 0.1
  |
  */
  'threshold' => env('MEDLAZYLOAD_THRESHOLD', 0.1),

];
