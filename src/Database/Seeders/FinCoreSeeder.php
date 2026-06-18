<?php

namespace Nml\FinCore\Database\Seeders;

use Illuminate\Database\Seeder;
use Nml\FinCore\Services\ChartOfAccountsInitializer;

class FinCoreSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        ChartOfAccountsInitializer::initialize();
    }
}
