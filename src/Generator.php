<?php

namespace Statamic\StaticSite;

use Statamic\Facades\URL;
use Illuminate\Support\Arr;
use Statamic\Facades\Entry;
use Statamic\Routing\Route;
use Statamic\Routing\Router;
use League\Flysystem\Adapter\Local;
use Statamic\Imaging\ImageGenerator;
use Illuminate\Filesystem\Filesystem;
use Statamic\Imaging\StaticUrlBuilder;
use Statamic\Contracts\Imaging\UrlBuilder;
use Statamic\Exceptions\RedirectException;
use League\Flysystem\Filesystem as Flysystem;
use Statamic\Exceptions\UrlNotFoundException;
use Wilderborn\Partyline\Facade as Partyline;
use Illuminate\Contracts\Foundation\Application;

class Generator
{
    protected $app;
    protected $files;
    protected $config;
    protected $request;
    protected $router;
    protected $after;

    public function __construct(Application $app, Filesystem $files, Router $router)
    {
        $this->app = $app;
        $this->files = $files;
        $this->router = $router;
        $this->config = config('statamic.static_site');
    }

    public function after($after)
    {
        $this->after = $after;

        return $this;
    }

    public function generate()
    {
        $this
            ->bindGlide()
            ->clearDirectory()
            ->createContentFiles()
            ->createSymlinks()
            ->copyFiles();

        Partyline::info('Static site generated into ' . $this->config['destination']);

        if ($this->after) {
            call_user_func($this->after);
        }
    }

    public function bindGlide()
    {
        $directory = Arr::get($this->config, 'glide.directory');

        $this->app['League\Glide\Server']->setCache(
            new Flysystem(new Local($this->config['destination'] . '/' . $directory))
        );

        $this->app->bind(UrlBuilder::class, function () use ($directory) {
            return new StaticUrlBuilder($this->app[ImageGenerator::class], [
                'route' => URL::tidy($this->config['base_url'] . '/' . $directory)
            ]);
        });

        return $this;
    }

    public function clearDirectory()
    {
        $this->files->deleteDirectory($this->config['destination'], true);

        return $this;
    }

    public function createSymlinks()
    {
        foreach ($this->config['symlinks'] as $source => $dest) {
            $dest = $this->config['destination'] . '/' . $dest;

            if ($this->files->exists($dest)) {
                Partyline::line("Symlink not created. $dest already exists.");
            } else {
                $this->files->link($source, $dest);
                Partyline::line("$source symlinked to $dest");
            }
        }

        return $this;
    }

    public function copyFiles()
    {
        foreach ($this->config['copy'] ?? [] as $source => $dest) {
            $dest = $this->config['destination'] . '/' . $dest;
            $this->files->copyDirectory($source, $dest);
            Partyline::line("$source copied to to $dest");
        }
    }

    protected function createContentFiles()
    {
        $pages = $this->pages();

        $request = tap(Request::capture(), function ($request) {
            $request->setConfig($this->config);
            $this->app->instance('request', $request);
        });

        $pages->each(function ($page) use ($request) {
            $request->setPage($page);

            Partyline::comment("Generating {$page->url()}...");

            try {
                $page->generate($request);
                Partyline::line(sprintf('%s%s %s', "\x1B[1A\x1B[2K", '<info>[✔]</info>', $page->url()));
            } catch (NotGeneratedException $e) {
                Partyline::line($this->notGeneratedMessage($e));
            }
        });

        return $this;
    }

    protected function pages()
    {
        return $this->content()
            ->merge($this->routes())
            ->values()
            ->reject(function ($page) {
                return in_array($page->url(), $this->config['exclude']);
            })->sortBy(function ($page) {
                return str_replace('/', '', $page->url());
            });
    }

    protected function content()
    {
        return Entry::all()->map(function ($content) {
            return $this->createPage($content);
        })->filter->isGeneratable();
    }

    protected function routes()
    {
        $routes = collect(config('statamic.routes.routes'))->reject(function ($data, $url) {
            return $this->router->hasWildCard($url);
        });

        $routes = $this->router->standardize($routes->all());

        return collect($routes)->map(function ($data, $url) {
            return $this->createPage(new Route($url, $data));
        });
    }

    protected function createPage($content)
    {
        return new Page($this->files, $this->config, $content);
    }

    protected function notGeneratedMessage($e)
    {
        switch (get_class($previous = $e->getPrevious())) {
            case UrlNotFoundException::class:
                $message = 'Resulted in 404';
                break;
            case RedirectException::class:
                $message = sprintf('Resulted in a %s redirect to %s', $previous->getStatusCode(), $previous->getUrl());
                break;
            default:
                $message = $e->getMessage();
        }

        return sprintf('%s %s (%s)', "\x1B[1A\x1B[2K<fg=red>[✘]</>", $e->getPage()->url(), $message);
    }
}