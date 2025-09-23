<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Base Settings
    |--------------------------------------------------------------------------
    */
    'base_url'     => env('APP_URL', 'https://example.com'), // Main site URL
    'xdefault'     => 'https://example.com',                 // Default hreflang URL

    /*
    |--------------------------------------------------------------------------
    | Validation & Error Handling
    |--------------------------------------------------------------------------
    */
    'validate_links'   => false,  // Check if URLs return valid responses
    'indexability_audit' => true, // Detect <meta noindex> and X-Robots-Tag headers
    'max_errors'       => 5000,   // Maximum number of errors stored in errors.xml

    /*
    |--------------------------------------------------------------------------
    | Exclusions
    |--------------------------------------------------------------------------
    */
    'exclude_urls' => [
        '/admin',               // plain string
        '/login',
        '/register',
        '.png',                 // extension
        '#\?page=\d+#',         // regex: skip pagination (?page=1, ?page=2, …)
        '#/search#',            // regex: skip search pages
        '#\.(css|js)$#',        // regex: skip static assets
    ],

    'exclude_assets' => [
        '#\.(css|js|json|xml|txt|md)$#',
        '#\.(zip|rar|tar|gz|7z)$#',
    ],

    /*
    |--------------------------------------------------------------------------
    | URL Normalization
    |--------------------------------------------------------------------------
    */
    'normalize' => [
        'strip_queries'        => true,  // remove ?utm=... or ?foo=bar
        'strip_anchors'        => true,  // remove #section1
        'canonicalize'         => true,  // force lowercase URLs
        'enforce_https'        => true,  // force https:// scheme
        'enforce_www'          => null,  // null = no change, true = add www, false = remove www
        'strip_trailing_slash' => true,  // remove trailing slash (except root)
        'force_trailing_slash' => false, // override: always add trailing slash
    ],

    /*
    |--------------------------------------------------------------------------
    | Multilingual Settings
    |--------------------------------------------------------------------------
    */
    'default_lang' => 'en',
    'lang_mode'    => 'path', // options: path (/en/page), subdomain (en.example.com), query (?lang=en)
    'validate_alternates'   => false,  // Check if alternates URLs return valid responses

    'alternates' => [
        'en' => 'https://example.com/en',
        'ar' => 'https://example.com/ar',
        'tr' => 'https://example.com/tr',
    ],

    /*
    |--------------------------------------------------------------------------
    | Include/Exclude by Content Type
    |--------------------------------------------------------------------------
    */
    'include' => [
        'urls'      => true,   // Always include URLs
        'images'    => true,   // Global default for images
        'videos'    => true,   // Global default for videos
        'languages' => true,   // Include <xhtml:link> alternates

        // Fine-grained rules (regex or prefix-based)
        'rules' => [
            '#/blog#' => [
                'images' => true,
                'videos' => false,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Image Settings
    |--------------------------------------------------------------------------
    */
    'image_whitelist' => [
        // '/storage/uploads/services/',
        // '/storage/uploads/blogs/',
        // '/storage/uploads/gallery/',
    ],

    'image_defaults' => [
        'title'       => 'Default Image Title',
        'description' => 'Default Image Description',
    ],

    /*
    |--------------------------------------------------------------------------
    | Video Settings
    |--------------------------------------------------------------------------
    */
    'video_whitelist' => [
        // '/storage/uploads/services/',
        // '/storage/uploads/blogs/',
        // '/storage/uploads/gallery/',
    ],

    'video_defaults' => [
        'title'       => 'Default Video Title',
        'description' => 'Default Video Description',
    ],

    /*
    |--------------------------------------------------------------------------
    | SEO Defaults & Rules
    |--------------------------------------------------------------------------
    */
    'defaults' => [
        'changefreq' => 'weekly',
        'priority'   => '0.8',
        'lastmod'    => 'now', // fallback: always now()
    ],

    'rules' => [
        // Homepage
        '/$' => [
            'changefreq' => 'daily',
            'priority'   => '1.0',
            'lastmod'    => 'now',
        ],

        // Blog posts (DB-based)
        '/blog' => [
            'changefreq'    => 'daily',
            'priority'      => '0.9',
            'lastmod'       => [
                'strategy' => 'db',
                'table'    => 'posts',
                'lookup'   => 'slug',
                'column'   => 'updated_at',
            ],
        ],

        // Services (DB-based, multilingual)
        '#^/(en|ar|tr)?/service#' => [
            'changefreq'    => 'weekly',
            'priority'      => null, // null → auto-priority scoring
            'priority_boost' => 0.3,
            'lastmod'       => [
                'strategy' => 'db',
                'table'    => 'services',
                'lookup'   => 'slug',
                'column'   => 'updated_at',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sitemap File Limits
    |--------------------------------------------------------------------------
    */
    'max_urls_per_sitemap' => 50000,    // Google’s hard limit
    'max_file_size'        => 52428800, // 50MB (bytes)
    'use_index'            => true,     // Generate sitemap-index.xml if split is needed

    /*
    |--------------------------------------------------------------------------
    | Search Engine Ping
    |--------------------------------------------------------------------------
    */
    'ping_targets' => [
        'Google' => 'https://www.google.com/ping?sitemap=',
        // Optional endpoints; user should verify if they work for their site
        // 'Bing'   => 'https://www.bing.com/webmaster/ping.aspx?siteMap=',  
        // 'Yandex' => 'https://webmaster.yandex.com/api/v4/user/{user-id}/hosts/{host-id}/user-added-sitemaps',
        // 'IndexNow' => 'https://api.indexnow.org/?key={your_indexnow_key}&url=', 
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Settings
    |--------------------------------------------------------------------------
    */
    'queue' => [
        'enabled'    => false,
        'connection' => 'default',
        'batch_size' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Priority Scoring (Auto SEO Ranking)
    |--------------------------------------------------------------------------
    */
    'priority_scoring' => [
        'enabled' => true,
        'weights' => [
            'depth'     => 0.4,
            'links'     => 0.4,
            'freshness' => 0.2,
        ],
        'min' => 0.1,
        'max' => 1.0,
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Client Settings (Guzzle)
    |--------------------------------------------------------------------------
    */
    'http' => [
        'validate_links' => [
            'timeout'         => 10,
            'connect_timeout' => 5,
            'verify'          => false,
            'http_errors'     => false,
            'headers'         => [
                'User-Agent' => 'LaracrawlerBot/1.0 (' . rtrim(config('sitemap.base_url'), '/') . ')',
            ],
        ],
        'validate_alternates' => [
            'timeout'         => 5,
            'connect_timeout' => 1,
            'verify'          => false,
            'http_errors'     => false,
            'headers'         => [
                'User-Agent' => 'LaracrawlerBot/1.0 (' . rtrim(config('sitemap.base_url'), '/') . ')',
            ],
        ],
    ],
];
