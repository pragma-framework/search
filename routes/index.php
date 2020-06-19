<?php
use Pragma\Router\Router;
use Pragma\Search\IndexerController;

$app = Router::getInstance();

$app->group('indexer:', function () use ($app) {
    $app->cli('run', function () {
        IndexerController::run();
    });
    $app->cli('rebuild', function () {
        IndexerController::rebuild();
    });
    $app->cli('indexed', function () {
        IndexerController::updateIndexed();
    });
});
