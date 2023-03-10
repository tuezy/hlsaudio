<?php

namespace Database\Seeders;

use App\Repository\Developments\DevelopmentContract;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

class PermissionSeeder extends Seeder
{
    protected $dev;
    public function __construct(DevelopmentContract $dev)
    {
        $this->dev = $dev;
    }

    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        Artisan::call("permission:reload-dashboard");
    }
}
