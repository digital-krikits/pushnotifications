<?php namespace Digitalkrikits\Pushnotifications;

use Illuminate\Support\ServiceProvider;

class PushnotificationsServiceProvider extends ServiceProvider {

	/**
	 * Indicates if loading of the provider is deferred.
	 *
	 * @var bool
	 */
	protected $defer = false;

	/**
	 * Register the service provider.
	 *
	 * @return void
	 */
	public function register()
	{

        $this->app['pushnotifications'] = $this->app->share(function($app) {
            $config = $app['config']->get('pushnotifications');
            return new Pushnotifications($config);
        });
	}

    public function boot()
    {

        $this->publishes([
            __DIR__.'/../../config/app.php' => config_path('dkpush.php')
        ]);
    }

	/**
	 * Get the services provided by the provider.
	 *
	 * @return array
	 */
	public function provides()
	{
		return ['pushnotifications'];
	}

}
