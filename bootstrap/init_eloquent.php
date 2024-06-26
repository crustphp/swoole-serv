<?php
// Must use Laravel
$manager = new \Illuminate\Database\Capsule\Manager;
foreach( \config('database.connections') as $name => $connection ){
    $manager->addConnection(
        $connection,
        $name
    );
}
// Set the event dispatcher
$manager->setEventDispatcher(new \Illuminate\Events\Dispatcher);
//Make this Capsule instance available globally
$manager->setAsGlobal();
// Bootstrap Eloquent
$manager->bootEloquent();
