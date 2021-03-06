<?php

use Pagekit\Application;
use Shw\Gallery\Events\RouteListener;
use Shw\Gallery\Content\MiniGalleryPlugin;

/*
 * This array is the module definition.
 * It's used by Pagekit to load your extension and register all things
 * that your extension provides (routes, menu items, php classes etc)
 */
return [

    'name' => 'gallery',
    'type' => 'extension',
    'main' => function (Application $app) {

    },

    /*
     * Register all namespaces to be loaded.
     * Map from namespace to folder where the classes are located.
     * Remember to escape backslashes with a second backslash.
     */
    'autoload' => [

        'Shw\\Gallery\\' => 'src',

    ],

    /*
     * Define nodes. A node is similar to a route with the difference
     * that it can be placed anywhere in the menu structure. The
     * resulting route is therefore determined on runtime.
     */
    'nodes' => [

        'Gallery' => [

            // The name of the node route
            'name' => '@gallery',

            // Label to display in the backend
            'label' => 'Gallery',

            // The controller for this node. Each controller action will be mounted
            'controller' => 'Shw\\Gallery\\Controller\\SiteController',

            // A unique node that cannot be deleted, resides in "Not Linked" by default
            'protected' => true

        ]

    ],


    /*
     * Define routes.
     */
    'routes' => [

        '/gallery' => [
            'name' => '@gallery/admin',
            'controller' => [
                'Shw\\Gallery\\Controller\\GalleryController'
            ]
        ],
        '/api/gallery' => [
            'name' => '@gallery/api',
            'controller' => [
                'Shw\\Gallery\\Controller\\GalleryApiController',
                'Shw\\Gallery\\Controller\\ImageApiController'
            ]
        ]

    ],

    /*
     * Define menu items for the backend.
     */
    'menu' => [

        // name, can be used for menu hierarchy
        'gallery' => [

            'label' => 'Gallery',
            'icon' => 'gallery:icon.svg',
            'url' => '@gallery/admin',
            'access' => 'gallery: manage own galleries'
        ],

        'gallery: panel' => [

            // Parent menu item, makes this appear on 2nd level
            'parent' => 'gallery',

            'label' => 'Gallery',
            'icon' => 'gallery:icon.svg',
            'url' => '@gallery/admin',
            'access' => 'gallery: manage own galleries'
        ],

        'gallery: settings' => [
            'parent' => 'gallery',
            'label' => 'Settings',
            'url' => '@gallery/admin/settings',
            'access' => 'gallery: manage settings'
        ]

    ],

    /*
     * Define permissions.
     * Will be listed in backend and can then be assigned to certain roles.
     */
    'permissions' => [

        'gallery: manage own galleries' => [
            'title' => 'Manage own galleries',
            'description' => 'Create, edit, delete and publish galleries of their own'
        ],
        'gallery: manage all galleries' => [
            'title' => 'Manage all galleries',
            'description' => 'Create, edit, delete and publish galleries by all users'
        ],
        'gallery: manage settings' => [
            'title' => 'Manage settings'
        ],

    ],

    /*
     * Link to a settings screen from the extensions listing.
     */
    'settings' => '@gallery/admin/settings',

    /*
     * Default module configuration.
     * Can be overwritten by changed config during runtime.
     */
    'config' => [
        'gallery' => [
            'title' => 'Gallery',
            'back_button' => false
        ],

        'images' => [
            'image_width'   => 1200,
            'image_height'  => 1200,
            'thumbnail_width'   => 150,
            'thumbnail_height'  => 100,
            'image_quality'  => 90,
        ]
    ],

    /*
     * Listen to events.
     */
    'events' => [

        'boot' => function ($event, $app) {
            $app->subscribe(
                new RouteListener,
                new MiniGalleryPlugin
            );
        },

        'view.scripts' => function ($event, $scripts) {
            $scripts->register('gallery-link', 'gallery:app/bundle/link-gallery.js', '~panel-link');
            $scripts->register('gallery-dashboard', 'gallery:app/bundle/gallery-dashboard.js', '~dashboard');
            $scripts->register('gallery-meta', 'gallery:app/bundle/gallery-meta.js', '~gallery-edit');
            $scripts->register('gallery-images', 'gallery:app/bundle/gallery-images.js', '~gallery-edit');
            //$scripts->register('minigallery', 'gallery:app/bundle/minigallery.js', ['~editor']);
        }

    ]

];
