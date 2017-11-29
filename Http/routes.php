<?php
/**
 * General
 */
Route::group(array('middleware' => 'userInfo'), function () {
    Route::get('/', 'HomeController@index');
    Route::get('cinemas', 'HomeController@cinemas');
    Route::get('aansluiten/callmeback', 'Admin\CompaniesCallcenterController@callMeBack');
    Route::get('contact', 'HomeController@contact');
    Route::get('review/{id}', 'HomeController@review');
    Route::get('create-ics', 'HomeController@createIcs');
    Route::get('open-menu', 'HomeController@index');
    Route::get('search', 'HomeController@search');
    Route::get('times', 'HomeController@times');
    Route::get('faq/{id?}/{slug?}', 'HomeController@faq');
    Route::get('veel-gestelde-vragen', 'HomeController@faq');
    Route::get('setlang/{lang}', 'HomeController@setLang');
    Route::get('redirect_to', 'HomeController@redirectTo');
    Route::get('captcha-handler', 'LaravelCaptcha\Controllers\CaptchaHandlerController@index');
    Route::get('a', 'HomeController@sourceRedirect');

    # FoodSupplies #
    Route::group(array('prefix' => 'voedingsmiddelen'), function () {
        Route::get('/{slug?}', 'FoodController@index');
    });
    
    Route::group(array('prefix' => 'oefeningen'), function () {
        Route::get('/{slug?}', 'PracticeController@index');
    });
    
    Route::group(array('prefix' => 'bibliotheek'), function () {
        Route::get('/', 'LibraryController@index');
        Route::get('/overzicht', 'LibraryController@library');
        Route::get('/overzicht/{slug?}', 'LibraryController@library');
        Route::get('/videos', 'LibraryController@videos');
        Route::get('/videos/{slug?}', 'LibraryController@videos');
    });
    
    ## Post routes - Preferences ##
    Route::post('aansluiten/callmeback', 'Admin\CompaniesCallcenterController@callMeBackAction');
    Route::post('preferences', 'HomeController@preferences');
    Route::post('contact', 'HomeController@contactAction');
    Route::post('search-redirect', 'HomeController@searchRedirect');
});

Route::group(array('middleware' => 'userInfo'), function() {
    Route::get('restaurant/{slug}', 'RestaurantController@index');
});


Route::group(array('prefix' => 'compare', 'middleware' => 'userInfo'), function () {
    Route::get('/', 'CompareController@index');
    Route::get('car', 'CompareController@car');
    Route::get('energy', 'CompareController@energy');
    Route::get('contents ', 'CompareController@contents');
    Route::get('building', 'CompareController@building');
    Route::get('law', 'CompareController@law');
    Route::get('travel', 'CompareController@travel');
    Route::get('care', 'CompareController@care');   

});
/**
 * Voordeelpas
 */
Route::group(array('prefix' => 'voordeelpas', 'middleware' => 'userInfo'), function () {
    Route::get('/', 'DiscountController@buy');
    Route::get('buy', 'DiscountController@buy');
    Route::get('buy/direct', 'DiscountController@buyDirect')->middleware(['auth']);

    ## Post routes - Buy ##
    Route::post('buy', 'DiscountController@buyAction')->middleware(['auth']);
});

/**
 * News
 */
Route::group(array('prefix' => 'news', 'middleware' => 'userInfo'), function () {
    Route::any('/', 'NewsController@index');
    Route::any('{slug}', 'NewsController@view')->where('slug', '[\-_A-Za-z0-9]+');
});

/**
 * Payments
 */
Route::group(array('prefix' => 'payment', 'middleware' => array('userInfo')), function () {
    Route::get('directory', 'PaymentController@updateDirectory');
    Route::get('mollie', 'PaymentController@testMollie');
    Route::get('status/{invoicenumber}', 'PaymentController@validatePaymentInvoice');
    Route::get('success', 'PaymentController@validatePayment');
    Route::get('charge', 'PaymentController@charge')->middleware(['auth']);
    Route::get('pay', 'PaymentController@charge')->middleware(['auth']);

    Route::get('pay-invoice/pay/{invoicenumber}', 'PaymentController@invoiceToPayment')->middleware(['auth']);

    ## Post routes - Payment ##
    Route::post('pay', 'PaymentController@initiateIdealPayment');
    Route::post('pay-invoice/pay', 'PaymentController@directInvoiceToPayment')->middleware(['auth']);
});


/**
 * Ajax
 */
Route::group(array('prefix' => 'ajax', 'middleware' => 'userInfo'), function() {
    Route::get('barcodes/popup', 'AjaxController@popupBarcodes');
    Route::get('users/regio', 'AjaxController@usersSetRegio');
    Route::get('users', 'AjaxController@users')->middleware(['admin']);
    Route::get('notifications', 'AjaxController@notifications');
    Route::get('faq', 'AjaxController@faqSearch');
    Route::get('appointments/companies', 'AjaxController@appointmentCompanies');
    Route::get('faqs', 'AjaxController@faq');
    Route::get('faq/subcategories', 'AjaxController@faqSubCategories');

    Route::get('companies/documents', 'AjaxController@adminCompaniesContract')->middleware(['adminowner', 'auth']);
    Route::get('companies/nearby', 'AjaxController@nearbyCompanies');
    Route::get('companies/nearby/company', 'AjaxController@nearbyCompany');
    Route::get('companies/invoices', 'AjaxController@adminCompaniesInvoices');
    Route::get('companies/popup', 'AjaxController@popupCompanies');
    Route::get('companies/users', 'AjaxController@usersCompanies');
    Route::get('companies/barcodes', 'AjaxController@barcodesCompanies')->middleware(['admin']);
    Route::get('companies', 'AjaxController@adminCompanies')->middleware(['admin']);
    Route::get('companies/owners', 'AjaxController@adminCompaniesOwners')->middleware(['admin']);
    Route::get('companies/waiters', 'AjaxController@adminCompaniesWaiters')->middleware(['admin']);
    Route::get('companies/callers', 'AjaxController@adminCompaniesCallers')->middleware(['admin']); 
    Route::get('services', 'AjaxController@adminCompaniesServices')->middleware(['admin']);

    // Post routes - Cookies
    Route::post('mailtemplates', 'AjaxController@mailtemplates');
    Route::post('cookies', 'AjaxController@cookies');
});

/**
 * Guests
 */
Route::group(array('middleware' => 'userInfo'), function() {
    Route::get('auth', 'Auth\AuthController@auth');
    Route::get('auth/set/{authCode}', 'Auth\AuthController@authSet');
    Route::get('register', 'Auth\AuthController@register');
    Route::get('logout', 'Auth\AuthController@logout');
    Route::get('login', 'Auth\AuthController@login');
    Route::get('login/redirect', 'Auth\AuthController@loginRedirect');
    Route::get('forgot-password', 'Auth\AuthController@forgotPassword');
    Route::get('activate/{code}', 'Auth\AuthController@activate');
    Route::get('activate-password/{code}', 'Auth\AuthController@activatePassword');
    Route::get('send-again/{code}', 'Auth\AuthController@sendMailAgain');

    ## Post routes - Guests ##
    Route::get('auth/remove', 'Auth\AuthController@authRemove');
    Route::post('activate-password/{code}', 'Auth\AuthController@activatePasswordAction');
    Route::post('forgot-password', 'Auth\AuthController@forgotPasswordAction');
    Route::post('register', 'Auth\AuthController@registerAction');
    Route::post('login', 'Auth\AuthController@loginAction');
});

/**
 *  Account
 */
Route::group(array('middleware' => array('auth', 'userInfo')), function () {
    Route::group(array('prefix' => 'account'), function () {
        Route::get('/', 'AccountController@settings');
        Route::get('barcodes', 'AccountController@barcodes');
        Route::get('activate-email/{code}', 'AccountController@activateEmail');

        ## Post routes - Account ##
        Route::post('delete', 'AccountController@deleteAccount');
        Route::post('/', 'AccountController@settingsAction');
        Route::post('barcodes', 'AccountController@barcodeAction');

        # Favorite #
        Route::group(array('prefix' => 'favorite'), function () {
            Route::get('companies', 'FavoriteCompaniesController@index');
            Route::get('companies/add/{id}/{slug}', 'FavoriteCompaniesController@add');
            Route::get('companies/remove/{id}/{slug}', 'FavoriteCompaniesController@remove');
        });
    });
});

/**
 *  Callcenter / Admin
 */
Route::group(array('prefix' => 'admin', 'middleware' => array('callcenter', 'auth', 'userInfo')), function () {
    # Appointments #
    Route::group(array('prefix' => 'appointments'), function () {
        Route::get('/', 'Admin\AppointmentController@index');
        Route::get('create/{slug?}', 'Admin\AppointmentController@create');
        Route::get('update/{id}', 'Admin\AppointmentController@update');

        Route::post('update/{id}', 'Admin\AppointmentController@updateAction');
        Route::post('create/{slug?}', 'Admin\AppointmentController@createAction');
        Route::post('/', 'Admin\AppointmentController@indexAction');
    });

    # Callcenter Companies #
    Route::group(array('prefix' => 'companies/callcenter'), function () {
        Route::get('/', 'Admin\CompaniesCallcenterController@index');
        Route::get('create', 'Admin\CompaniesCallcenterController@create');
        Route::get('update/{id}/{slug}', 'Admin\CompaniesCallcenterController@update');
        Route::get('favorite/{id}', 'Admin\CompaniesCallcenterController@favorite');
        Route::get('export', 'Admin\CompaniesCallcenterController@export');
        Route::get('import', 'Admin\CompaniesCallcenterController@import');
        Route::get('contract/{id}/{slug}', 'Admin\CompaniesCallcenterController@contract');

        Route::post('import', 'Admin\CompaniesCallcenterController@importAction');
        Route::post('update/{id}/{slug}', 'Admin\CompaniesCallcenterController@updateAction');
        Route::post('create', 'Admin\CompaniesCallcenterController@createAction');
        Route::post('delete', 'Admin\CompaniesCallcenterController@deleteAction');
    });
});

/**
 *  Admin
 */
Route::group(array('prefix' => 'admin', 'middleware' => array('admin', 'auth', 'userInfo')), function () {
    # Ban #
    Route::group(array('prefix' => 'statistics'), function () {
        Route::get('search', 'Admin\StatisticsController@search');
    });

    Route::group(array('prefix' => 'bans'), function () {
        Route::get('/', 'Admin\UsersBanController@index');
        Route::get('create/{id?}', 'Admin\UsersBanController@create');
        Route::get('update/{id}', 'Admin\UsersBanController@update');

        Route::post('update/{id}', 'Admin\UsersBanController@updateAction');
        Route::post('create/{id?}', 'Admin\UsersBanController@createAction');
        Route::post('/', 'Admin\UsersBanController@indexAction');
        Route::post('delete', 'Admin\UsersBanController@deleteAction');
    });

    # Translations #
    Route::group(array('prefix' => 'translations'), function () {
        Route::get('/', 'Admin\TranslationController@getIndex');
        Route::get('view/{slug?}', 'Admin\TranslationController@getView');

        Route::post('publish/{slug?}', 'Admin\TranslationController@postPublish');
        Route::post('import/{slug?}', 'Admin\TranslationController@postImport');
    });  

    # Notifications groups #
    Route::group(array('prefix' => 'notifications/groups'), function () {
        Route::get('/', 'Admin\NotificationGroupController@index');
        Route::get('create', 'Admin\NotificationGroupController@create');
        Route::get('update/{id}', 'Admin\NotificationGroupController@update');

        Route::post('update/{id}', 'Admin\NotificationGroupController@updateAction');
        Route::post('create', 'Admin\NotificationGroupController@createAction');
        Route::post('/', 'Admin\NotificationGroupController@indexAction');
    });

    # Notifications #
    Route::group(array('prefix' => 'notifications'), function () {
        Route::get('/', 'Admin\NotificationController@index');
        Route::get('create', 'Admin\NotificationController@create');
        Route::get('update/{id}', 'Admin\NotificationController@update');

        Route::post('update/{id}', 'Admin\NotificationController@updateAction');
        Route::post('create', 'Admin\NotificationController@createAction');
        Route::post('/', 'Admin\NotificationController@indexAction');
    });

    # Settings  #
    Route::group(array('prefix' => 'settings'), function () {
        Route::get('/', 'Admin\SettingsController@index');
        Route::get('delete/image/{id}', 'Admin\SettingsController@deleteImage');
        Route::get('run/{slug}', 'Admin\SettingsController@run');

        Route::post('/', 'Admin\SettingsController@indexAction');
        Route::resource('website', 'Admin\SettingsController@websiteAction');
        Route::resource('discount', 'Admin\SettingsController@discountAction');
        Route::resource('cronjobs', 'Admin\SettingsController@cronjobsAction');
        Route::resource('invoices', 'Admin\SettingsController@invoicesAction');
    });

    # Payments #
    Route::group(array('prefix' => 'payments'), function () {
        Route::get('/', 'Admin\PaymentsController@index');
        Route::get('update/{id}', 'Admin\PaymentsController@update');

        Route::post('/', 'Admin\PaymentsController@indexAction');
        Route::post('update/{id}', 'Admin\PaymentsController@updateAction');
    });

    # Widgets #
    Route::get('widgets', 'Admin\CompaniesController@widgetsIndex');


    # Mail templates #
    Route::group(array('prefix' => 'mailtemplates'), function () {
        Route::get('/', 'Admin\MailTemplatesController@index');
        Route::get('settings', 'Admin\MailTemplatesController@settings');

        Route::post('settings', 'Admin\MailTemplatesController@settingsAction');
    });

    # Services #
    Route::group(array('prefix' => 'services'), function () {
        Route::get('create', 'Admin\ServicesController@create');
        Route::get('update/{id}', 'Admin\ServicesController@update');

        Route::post('create', 'Admin\ServicesController@createAction');
        Route::post('update/{id}', 'Admin\ServicesController@updateAction');
        Route::post('delete', 'Admin\ServicesController@deleteAction');

        Route::get('/{slug?}', 'Admin\ServicesController@index');
    });
    
    # FoodSupplies #
    Route::group(array('prefix' => 'food'), function () {
        Route::get('create', 'Admin\FoodController@create');
        Route::get('update/{id}', 'Admin\FoodController@update');

        Route::post('create', 'Admin\FoodController@createAction');
        Route::post('update/{id}', 'Admin\FoodController@updateAction');
        Route::post('delete', 'Admin\FoodController@deleteAction');

        Route::get('/{slug?}', 'Admin\FoodController@index');
    });

    # Practices #
    Route::group(array('prefix' => 'practice'), function () {
        Route::get('create', 'Admin\PracticeController@create');
        Route::get('update/{id}', 'Admin\PracticeController@update');

        Route::post('create', 'Admin\PracticeController@createAction');
        Route::post('update/{id}', 'Admin\PracticeController@updateAction');
        Route::post('delete', 'Admin\PracticeController@deleteAction');

        Route::get('/{slug?}', 'Admin\PracticeController@index');
    });

    # User #
    Route::group(array('prefix' => 'users'), function () {
        Route::get('/', 'Admin\UsersController@index');
        Route::get('create', 'Admin\UsersController@create');
        Route::get('update/{id}', 'Admin\UsersController@update');
        Route::get('login/{id}', 'Admin\UsersController@login');
        Route::get('activate/{id}', 'Admin\UsersController@activate');

        Route::post('create', 'Admin\UsersController@createAction');
        Route::post('update/{id}', 'Admin\UsersController@updateAction');
        Route::post('delete', 'Admin\UsersController@deleteAction');
    });

    # Roles #
    Route::group(array('prefix' => 'roles'), function () {
        Route::get('/', 'Admin\RolesController@index');
        Route::get('create', 'Admin\RolesController@create');
        Route::get('update/{id}', 'Admin\RolesController@update');

        Route::post('update/{id}', 'Admin\RolesController@updateAction');
        Route::post('create', 'Admin\RolesController@createAction');
        Route::post('delete', 'Admin\RolesController@deleteAction');
    });

    # Companies #
    Route::group(array('prefix' => 'companies'), function () {
        Route::get('/', 'Admin\CompaniesController@index');
        Route::get('create', 'Admin\CompaniesController@create');
        Route::get('login/{slug}', 'Admin\CompaniesController@login');

        Route::post('create', 'Admin\CompaniesController@createAction');
        Route::post('delete', 'Admin\CompaniesController@deleteAction');
    });
    
    # Pages #
    Route::group(array('prefix' => 'pages'), function () {
        Route::get('/', 'Admin\PagesController@index');
        Route::get('create', 'Admin\PagesController@create');
        Route::get('update/{id}', 'Admin\PagesController@update');

        Route::post('create', 'Admin\PagesController@createAction');
        Route::post('update/{id}', 'Admin\PagesController@updateAction');
        Route::post('delete', 'Admin\PagesController@deleteAction');
    });

    # Barcodes #
    Route::group(array('prefix' => 'barcodes'), function () {
        Route::get('/', 'Admin\BarcodesController@index');
        Route::get('create', 'Admin\BarcodesController@create');
        Route::get('update/{id}', 'Admin\BarcodesController@update');
        Route::get('success', 'Admin\BarcodesController@validatePayment');

        Route::post('buy', 'Admin\BarcodesController@buyAction');
        Route::post('create', 'Admin\BarcodesController@createAction');
        Route::post('update/{id}', 'Admin\BarcodesController@updateAction');
        Route::post('delete', 'Admin\BarcodesController@deleteAction');
    });

    # Content blocks #
    Route::group(array('prefix' => 'contents'), function () {
        Route::get('/', 'Admin\ContentsController@index');
        Route::get('create', 'Admin\ContentsController@create');
        Route::get('update/{id}', 'Admin\ContentsController@update');

        Route::post('create', 'Admin\ContentsController@createAction');
        Route::post('update/{id}', 'Admin\ContentsController@updateAction');
        Route::post('delete', 'Admin\ContentsController@deleteAction');
    });

    # FAQ # 
    Route::group(array('prefix' => 'faq'), function () {
        Route::get('/', 'Admin\FaqController@index');
        Route::get('create', 'Admin\FaqController@create');
        Route::get('update/{id}', 'Admin\FaqController@update');

        Route::post('create', 'Admin\FaqController@createAction');
        Route::post('update/{id}', 'Admin\FaqController@updateAction');
        Route::post('delete', 'Admin\FaqController@deleteAction');
    });

    # FAQ # 
    Route::group(array('prefix' => 'faq/categories'), function () {
        Route::get('/', 'Admin\FaqCategoryController@indexParent');
        Route::get('create/parent', 'Admin\FaqCategoryController@createParent');
        Route::get('update/child/{id}', 'Admin\FaqCategoryController@updateChild');
        Route::get('update/parent/{id}', 'Admin\FaqCategoryController@updateParent');
        Route::get('children', 'Admin\FaqCategoryController@indexChild');
        Route::get('create/child', 'Admin\FaqCategoryController@createChild'); 

        Route::post('create/child', 'Admin\FaqCategoryController@createChildAction');
        Route::post('create/parent', 'Admin\FaqCategoryController@createParentAction');
        Route::post('update/parent/{id}', 'Admin\FaqCategoryController@updateParentAction');
        Route::post('update/child/{id}', 'Admin\FaqCategoryController@updateChildAction');
        Route::post('delete', 'Admin\FaqCategoryController@deleteAction');
    });

    # Preferences #
    Route::group(array('prefix' => 'preferences'), function () {
        Route::get('/', 'Admin\PreferencesController@index');
        Route::get('create', 'Admin\PreferencesController@create');
        Route::get('update/{id}', 'Admin\PreferencesController@update');

        Route::post('create', 'Admin\PreferencesController@createAction');
        Route::post('update/{id}', 'Admin\PreferencesController@updateAction');
        Route::post('delete', 'Admin\PreferencesController@deleteAction');
    });

    # Invoices #
    Route::group(array('prefix' => 'invoices'), function () {
        Route::get('/', 'Admin\InvoicesController@index');
        Route::get('create', 'Admin\InvoicesController@create');
        Route::get('update/{id}', 'Admin\InvoicesController@update');
        Route::get('setpaid', 'Admin\InvoicesController@setPaid');
        Route::get('send/{id}', 'Admin\InvoicesController@sendInvoice');
        Route::get('tax/report', 'Admin\InvoicesController@taxReport');

        Route::post('update/{id}', 'Admin\InvoicesController@updateAction');
        Route::post('action', 'Admin\InvoicesController@invoicesAction');
        Route::post('create', 'Admin\InvoicesController@createAction');
    });
});

/**
 *  Waiter / Admin / Company
 */
Route::group(array('prefix' => 'admin', 'middleware' => array('waiter', 'auth', 'userInfo')), function () {
    # Reviews #
    Route::get('reviews/{slug}', 'Admin\ReviewsController@index');

});

/**
 *  Admin / Company
 */
Route::group(array('prefix' => 'admin', 'middleware' => array('adminowner', 'auth', 'userInfo')), function () {

    # Barcodes #
    Route::group(array('prefix' => 'barcodes'), function () {
        Route::get('{slug}', 'Admin\BarcodesController@company');
    });

    # Invoices #
    Route::group(array('prefix' => 'invoices'), function () {
        Route::get('download/{id}', 'Admin\InvoicesController@downloadInvoice');
        Route::get('overview/{slug}', 'Admin\InvoicesController@index');
    });

    # Companies #
    Route::group(array('prefix' => 'companies'), function () {
        Route::get('update/{id}/{slug}', 'Admin\CompaniesController@update');
        Route::get('crop/image/{slug}/{image}', 'Admin\CompaniesController@cropImage');
        Route::get('delete/image/{slug}/{image}', 'Admin\CompaniesController@deleteImage');
        Route::get('contract/{id}/{slug}', 'Admin\CompaniesController@contract');

        Route::post('crop/image/{slug}/{image}', 'Admin\CompaniesController@cropImageAction');
        Route::post('update/{id}/{slug}', 'Admin\CompaniesController@updateAction');
    });

    # Widgets #
    Route::get('widgets/{slug}', 'Admin\CompaniesController@widgets');

    # News #
    Route::group(array('prefix' => 'news'), function () {
        Route::get('{slug?}/create', 'Admin\NewsController@create');
        Route::get('update/{id}', 'Admin\NewsController@update');
        Route::get('{slug?}', 'Admin\NewsController@index');

        Route::post('{slug?}/create', 'Admin\NewsController@createAction');
        Route::post('update/{id}', 'Admin\NewsController@updateAction');
        Route::post('delete', 'Admin\NewsController@deleteAction');
    });

    # Mail templates #
    Route::group(array('prefix' => 'mailtemplates'), function () {
        Route::get('create/{slug?}', 'Admin\MailTemplatesController@create');
        Route::get('update/{id}', 'Admin\MailTemplatesController@update');
        Route::get('{slug}', 'Admin\MailTemplatesController@indexCompany');

        Route::post('create/{slug?}', 'Admin\MailTemplatesController@createAction');
        Route::post('update/{id}', 'Admin\MailTemplatesController@updateAction');
        Route::post('delete/{slug?}', 'Admin\MailTemplatesController@deleteAction');
        Route::post('search', 'Admin\MailTemplatesController@searchAction');
    });

});

/**
 *  Pages
 */
Route::any('{slug}', 'HomeController@page')->where('slug', '[\-_A-Za-z0-9]+')->middleware(array('userInfo'));

/**
 * Ajax
 */
Route::get('public/images/signatures/{filename}png', 'FileController@getFile')->where('filename', '(.*)');