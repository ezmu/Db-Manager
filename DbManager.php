<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Faker\Factory as Faker;
use PhpSchool\CliMenu\CliMenu;
use PhpSchool\CliMenu\Builder\CliMenuBuilder;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;

class DbManager extends Command {

    protected $signature = 'db:manage';
    protected $description = 'Manage database: show tables, insert fake data, etc.';
    protected array $visitedTables = [];
    protected $faker;
    protected $mainMenu;

    public function __construct() {
        parent::__construct();
        $this->faker = Faker::create();
    }

    public function handle() {
        $this->showMenu();
    }

    private function getDatabaseTables(): array {
        $results = DB::select('SHOW TABLES');
        $key = 'Tables_in_' . DB::getDatabaseName();

        $tables = [];
        foreach ($results as $row) {
            $tables[] = $row->$key;
        }

        return $tables;
    }

    protected function showMenu() {
        $menuBuilder = new CliMenuBuilder();
        $menuBuilder->setTitle('Database Manager')
                ->addItem('Show Tables', function () {
                    $this->showTablesMenu();
                })
                ->addItem('Insert Fake Data', function () {
                    $this->insertFakeDataMenu();
                })->addItem('Manage Seeds', function () {
                    $this->seedCreateMenu();
                })->addItem('View All Seeders', function (CliMenu $menu) {
                    $this->showSeeders($menu);
                })
                ->addItem('View All Migrations', function (CliMenu $menu) {
                    $this->showMigrations($menu);
                });

        $menu = $menuBuilder->build();
        $this->mainMenu = $menu;
        $menu->open();
    }

    function seedCreateMenu() {
        $seedMenuBuilder = new CliMenuBuilder();
        $seedMenuBuilder->setTitle('Select table To generate seed class');
        $tables = $this->getDatabaseTables(); // Implement this helper function

        foreach ($tables as $table) {
            $seedMenuBuilder->addItem($table, function () use ($table) {
                $this->showTableSeedOptionsMenu($table);
            });
        }
        $seedMenuBuilder->addLineBreak('-')->addItem('Back', fn(CliMenu $m) => $this->showMenu());
        $seedMenu = $seedMenuBuilder->build();
        $seedMenu->open();
    }

    protected function showSeeders(CliMenu $menu) {
        $path = database_path('seeders');
        $files = File::files($path);

        $submenu = (new CliMenuBuilder)->setTitle('Seeders');

        foreach ($files as $file) {
            $name = $file->getFilename();
            $contents = File::get($file);

            $relatedTables = $this->extractTablesFromContent($contents);
            $submenu->addItem($name, fn(CliMenu $m) => $this->displayPopup("Seeder: $name\n\nRelated Tables:\n- " . implode("\n- ", $relatedTables)));
        }

        $submenu->addLineBreak('-')->addItem('Back', fn(CliMenu $m) => $this->showMenu());
        $submenu->build()->open();
    }

    protected function showTableSeedOptionsMenu(string $table) {


        $menuBuilder = new CliMenuBuilder();

        $menuBuilder->setTitle("Options for table: $table")
                ->addItem('Generate Seeder', function (CliMenu $menu) use ($table) {

                    $this->generateSeederForTable($table);
                })
                ->addItem('Generate Migration', function (CliMenu $menu) use ($table) {

                    $this->generateMigrationForTable($table);
                })
                ->addItem('Back', function (CliMenu $menu) {
                    $menu->close();
                    $this->showMenu(); // go back to main menu
                });

        $menu = $menuBuilder->build();
        $menu->open();
    }

    protected function showMigrations(CliMenu $menu) {
        $path = database_path('migrations');
        $files = File::files($path);

        $submenu = (new CliMenuBuilder)->setTitle('Migrations');

        foreach ($files as $file) {
            $name = $file->getFilename();
            $contents = File::get($file);
            $fields = $this->extractMigrationFields($contents);

            $submenu->addItem($name, fn(CliMenu $m) => $this->displayPopup("Migration: $name\n\nFields:\n" . implode("\n", $fields)));
        }

        $submenu->addLineBreak('-')->addItem('Back', fn(CliMenu $m) => $this->showMenu());
        $submenu->build()->open();
    }

    protected function extractTablesFromContent(string $content): array {
        preg_match_all('/table->insert\(\[.*?\'table\' => \'(.*?)\'/s', $content, $matches);
        if (!empty($matches[1])) {
            return array_unique($matches[1]);
        }

        preg_match_all("/DB::table\\(\\'([^']+)\\'\\)/", $content, $matches);
        return array_unique($matches[1] ?? []);
    }

    protected function extractMigrationFields(string $content): array {
        $fields = [];
        if (preg_match_all('/\\$table->(.*?)\((.*?)\)/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $type = $match[1];
                $column = trim($match[2], "'\"");
                $fields[] = "$column: $type";
            }
        }
        return $fields;
    }

    protected function displayPopup(string $text) {
        $popup = (new CliMenuBuilder)
                ->setTitle('Details');

        foreach (explode("\n", $text) as $line) {
            $popup->addStaticItem($line);
        }

        $popup
                ->addLineBreak('-')
                ->addItem('Back', fn($m) => $this->showMenu());

        $popup->build()->open();
    }

    function generateSeederForTable(string $table) {
        $columns = \Schema::getColumnListing($table);
        $rows = \DB::table($table)->limit(10)->get()->toArray(); // sample data

        $className = ucfirst(Str::camel($table)) . 'Seeder';
        $filePath = database_path("seeders/{$className}.php");

        $dataArray = var_export(array_map(fn($row) => (array) $row, $rows), true);

        $content = <<<PHP
<?php

use Illuminate\Database\Seeder;

class {$className} extends Seeder
{
    public function run()
    {
        DB::table('{$table}')->insert({$dataArray});
    }
}
PHP;

        file_put_contents($filePath, $content);

        echo "Seeder generated: {$filePath}\n";
    }

    function generateMigrationForTable(string $table) {
        $timestamp = date('Y_m_d_His');
        $className = 'Create' . ucfirst(Str::camel($table)) . 'Table';
        $fileName = $timestamp . '_create_' . Str::snake($table) . '_table.php'; 
        $filePath = database_path('migrations/' . $fileName);

        $schemaManager = $this->getSchemaManager();
        $columns = $schemaManager->listTableColumns($table);
        $foreignKeys = $schemaManager->listTableForeignKeys($table);
        $indexes = $schemaManager->listTableIndexes($table);

        $columnDefinitions = [];
        $uniqueConstraints = [];
        $foreignKeyConstraints = [];
        $timestampsAdded = false;
        $idAdded = false;

        foreach ($columns as $column) {
            $columnName = $column->getName();
            $columnType = $column->getType()->getName();
            $nullable = !$column->getNotnull() ? '->nullable()' : '';
            $default = $column->getDefault();
            $defaultClause = $default !== null ? "->default(" . $this->formatDefaultValue($default, $columnType) . ")" : '';

  
            if ($columnName === 'id' && $column->getAutoincrement() && in_array($column->getName(), $indexes)) {
                $columnDefinitions[] = "\$table->id();";
                $idAdded = true;
                continue;
            }
            if (($columnName === 'created_at' || $columnName === 'updated_at') && $columnType === 'datetime') {

                continue;
            }

            $laravelSchemaMethod = $columnType;
            $args = ["'{$columnName}'"];

            switch ($columnType) {
                case 'string':
                case 'text':
                    $length = $column->getLength();
                    if ($length) {
                        $args[] = $length;
                    }
                    $laravelSchemaMethod = 'string'; 
                    if ($columnType === 'text') {
                        $laravelSchemaMethod = 'text'; 
                    }
                    break;
                case 'integer':
                    $laravelSchemaMethod = 'integer';
                    break;
                case 'bigint':
                    $laravelSchemaMethod = 'bigInteger';
                    break;
                case 'smallint':
                    $laravelSchemaMethod = 'smallInteger';
                    break;
                case 'boolean':
                    $laravelSchemaMethod = 'boolean';
                    break;
                case 'date':
                    $laravelSchemaMethod = 'date';
                    break;
                case 'datetime':
                case 'datetimetz':
                case 'timestamp':
                    $laravelSchemaMethod = 'dateTime';
                    if ($columnType === 'timestamp') {
                        $laravelSchemaMethod = 'timestamp';
                    }
                    break;
                case 'float':
                    $laravelSchemaMethod = 'float';
                    break;
                case 'decimal':
                    $precision = $column->getPrecision();
                    $scale = $column->getScale();
                    $args[] = $precision;
                    $args[] = $scale;
                    $laravelSchemaMethod = 'decimal';
                    break;
                case 'json':
                    $laravelSchemaMethod = 'json';
                    break;
                case 'uuid':
                    $laravelSchemaMethod = 'uuid';
                    break;
                default:
                    $this->warn("Unknown column type '{$columnType}' for column '{$columnName}'. Defaulting to string.");
                    $laravelSchemaMethod = 'string';
                    break;
            }
     
            $columnDefinition = "\$table->{$laravelSchemaMethod}(" . implode(', ', $args) . "){$nullable}{$defaultClause};";
            $columnDefinitions[] = $columnDefinition;
        }


        foreach ($indexes as $index) {
            if ($index->isUnique() && !$index->isPrimary()) { // Exclude primary keys as id() handles it
                $indexColumns = array_map(fn($col) => "'{$col}'", $index->getColumns());
                if (count($indexColumns) === 1) {
                    // For single-column unique indexes, append ->unique() to the column definition
                    $targetColumnName = $index->getColumns()[0];
                    foreach ($columnDefinitions as $key => $def) {
                        if (Str::contains($def, "('{$targetColumnName}')")) {
                            $columnDefinitions[$key] = str_replace(';', '->unique();', $def);
                            break;
                        }
                    }
                } else {
                    $uniqueConstraints[] = "\$table->unique([" . implode(', ', $indexColumns) . "]);";
                }
            }
        }

        // Add foreign key constraints
        foreach ($foreignKeys as $fk) {
            $localCols = array_map(fn($col) => "'{$col}'", $fk->getLocalColumns());
            $foreignTable = $fk->getForeignTableName();
            $foreignCols = array_map(fn($col) => "'{$col}'", $fk->getForeignColumns());

            $onDelete = $fk->getOption('onDelete');
            $onUpdate = $fk->getOption('onUpdate');

            $fkDefinition = "\$table->foreign(" . implode(', ', $localCols) . ")->references(" . implode(', ', $foreignCols) . ")->on('{$foreignTable}')";
            if ($onDelete) {
                $fkDefinition .= "->onDelete('" . Str::lower($onDelete) . "')";
            }
            if ($onUpdate) {
                $fkDefinition .= "->onUpdate('" . Str::lower($onUpdate) . "')";
            }
            $fkDefinition .= ";";
            $foreignKeyConstraints[] = $fkDefinition;
        }

        $hasCreatedAt = false;
        $hasUpdatedAt = false;
        foreach ($columns as $column) {
            if ($column->getName() === 'created_at') {
                $hasCreatedAt = true;
            }
            if ($column->getName() === 'updated_at') {
                $hasUpdatedAt = true;
            }
        }
        if ($hasCreatedAt && $hasUpdatedAt && !$timestampsAdded) {
            $columnDefinitions[] = "\$table->timestamps();";
            $timestampsAdded = true;
        }


        $finalColumnLines = [];
        if ($idAdded) {
            $finalColumnLines[] = "\$table->id();"; // Ensure id() is first
        }

        foreach ($columnDefinitions as $def) {
            if (Str::startsWith($def, '$table->id();') || Str::startsWith($def, '$table->timestamps();')) {
                continue;
            }
            $finalColumnLines[] = $def;
        }

        $finalColumnLines = array_merge($finalColumnLines, $uniqueConstraints, $foreignKeyConstraints);

        if ($hasCreatedAt && $hasUpdatedAt && !$timestampsAdded) {
            $finalColumnLines[] = "\$table->timestamps();";
        }


        $allColumnLines = implode("\n            ", $finalColumnLines);

        $content = <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('{$table}', function (Blueprint \$table) {
            {$allColumnLines}
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('{$table}');
    }
};
PHP;

        file_put_contents($filePath, $content);
    }

    protected function formatDefaultValue($value, $type) {
        if ($value === null) {
            return 'null';
        }

        switch ($type) {
            case 'string':
            case 'text':
            case 'guid':
            case 'char':
            case 'varchar':
                return "'" . addslashes($value) . "'";
            case 'boolean':
                return $value ? 'true' : 'false';
            case 'integer':
            case 'smallint':
            case 'bigint':
            case 'float':
            case 'decimal':
                return $value;
            default:
                return "'" . addslashes($value) . "'"; // Default to string representation
        }
    }

    protected function getSchemaManager() {
        $pdo = DB::connection()->getPdo();
        $config = new \Doctrine\DBAL\Configuration();

        $driver = DB::getDriverName();

        $driverMap = [
            'mysql' => 'pdo_mysql',
            'pgsql' => 'pdo_pgsql',
            'sqlite' => 'pdo_sqlite',
            'sqlsrv' => 'pdo_sqlsrv',
        ];

        $doctrineDriver = $driverMap[$driver] ?? $driver;

        $connectionParams = [
            'pdo' => $pdo,
            'dbname' => DB::connection()->getDatabaseName(),
            'driver' => $doctrineDriver,
            'user' => config("database.connections.{$driver}.username"),
            'password' => config("database.connections.{$driver}.password"),
            'host' => config("database.connections.{$driver}.host"),
            'port' => config("database.connections.{$driver}.port"),
            'charset' => config("database.connections.{$driver}.charset"),
        ];

        $doctrineConnection = \Doctrine\DBAL\DriverManager::getConnection($connectionParams, $config);

        return $doctrineConnection->createSchemaManager();
    }

    protected function showTablesMenu() {
        $tables = DB::select('SHOW TABLES');
        $tableNames = array_map(fn($t) => array_values((array) $t)[0], $tables);

        $menuBuilder = new CliMenuBuilder();
        $menuBuilder->setTitle('Database Tables');

        foreach ($tableNames as $table) {
            $menuBuilder->addItem($table, function (CliMenu $menu) use ($table) {
                $menu->close();
                $this->showTableDataMenu($table);
            });
        }

        $menuBuilder->addLineBreak('-')
                ->addItem('Back', function (CliMenu $menu) {
                    $menu->close();
                    $this->showMenu();
                });

        $menu = $menuBuilder->build();
        $menu->open();
    }

    protected function insertFakeDataMenu() {
        $tables = DB::select('SHOW TABLES');
        $tableNames = array_map(fn($t) => array_values((array) $t)[0], $tables);

        $menuBuilder = new CliMenuBuilder();
        $menuBuilder->setTitle('Insert Fake Data');

        foreach ($tableNames as $table) {
            $menuBuilder->addItem($table, function (CliMenu $menu) use ($table) {
                $menu->close();
                $count = 10;
                $this->visitedTables = []; // reset recursion tracking for each table
                $this->insertFakeRows($table, $count);
            });
        }

        $menuBuilder->addLineBreak('-')
                ->addItem('Back', function (CliMenu $menu) {
                    $menu->close();
                });

        $menu = $menuBuilder->build();
        $menu->open();
    }

    protected function insertFakeRows(string $table, int $count) {
        if (in_array($table, $this->visitedTables)) {
            // Avoid infinite recursion on cyclic relations
            $this->info("Already visited '{$table}', skipping to avoid recursion.");
            return;
        }

        $this->visitedTables[] = $table;

        $columns = Schema::getColumnListing($table);
        $schemaManager = $this->getSchemaManager();

        $columnsDetails = [];
        foreach ($schemaManager->listTableColumns($table) as $col) {
            $columnsDetails[$col->getName()] = $col;
        }

        $foreignKeys = $schemaManager->listTableForeignKeys($table);

        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $row = [];

            foreach ($foreignKeys as $fk) {
                $localCols = $fk->getLocalColumns(); // array
                $foreignTable = $fk->getForeignTableName();
                $foreignCols = $fk->getForeignColumns();

                if (count($localCols) === 1 && count($foreignCols) === 1) {
                    $localCol = $localCols[0];
                    $foreignCol = $foreignCols[0];

                    $exists = DB::table($foreignTable)->exists();

                    if (!$exists) {
                        $this->info("Inserting related row into foreign table '{$foreignTable}' for foreign key on '{$table}.{$localCol}'");
                        $this->insertFakeRows($foreignTable, 1);
                    }

                    $foreignId = DB::table($foreignTable)->inRandomOrder()->value($foreignCol);
                    $row[$localCol] = $foreignId;
                }
            }

            // Generate fake data for other columns (excluding foreign keys, primary keys, timestamps)
            foreach ($columns as $col) {
                if (isset($row[$col])) {
                    continue;
                }

                if (in_array($col, ['id', 'created_at', 'updated_at', 'deleted_at'])) {
                    continue;
                }

                if ($col === 'password') {
                    $row[$col] = bcrypt('secret');
                    continue;
                }

                if ($col === 'remember_token') {
                    $row[$col] = Str::random(10);
                    continue;
                }

                $colDetails = $columnsDetails[$col] ?? null;

                if (!$colDetails) {
                    $row[$col] = $this->faker->word;
                    continue;
                }

                $typeClass = get_class($colDetails->getType());

                $typeMap = [
                    \Doctrine\DBAL\Types\StringType::class => 'string',
                    \Doctrine\DBAL\Types\TextType::class => 'text',
                    \Doctrine\DBAL\Types\IntegerType::class => 'integer',
                    \Doctrine\DBAL\Types\BigIntType::class => 'bigint',
                    \Doctrine\DBAL\Types\SmallIntType::class => 'smallint',
                    \Doctrine\DBAL\Types\BooleanType::class => 'boolean',
                    \Doctrine\DBAL\Types\DateTimeType::class => 'datetime',
                    \Doctrine\DBAL\Types\DateType::class => 'date',
                    \Doctrine\DBAL\Types\DecimalType::class => 'decimal',
                    \Doctrine\DBAL\Types\FloatType::class => 'float',
                    \Doctrine\DBAL\Types\DateTimeTzType::class => 'datetimetz',
                    \Doctrine\DBAL\Types\TimeType::class => 'time',
                ];

                $type = $typeMap[$typeClass] ?? 'string'; // fallback string               
                $nullable = !$colDetails->getNotnull();

                // Nullable chance
                if ($nullable && $this->faker->boolean(15)) {
                    $row[$col] = null;
                    continue;
                }

                switch ($type) {
                    case 'string':
                    case 'text':
                        if (Str::contains($col, 'email')) {
                            $row[$col] = $this->faker->unique()->safeEmail;
                        } elseif (Str::contains($col, 'name')) {
                            $row[$col] = $this->faker->name;
                        } elseif (Str::contains($col, 'title')) {
                            $row[$col] = $this->faker->sentence(3);
                        } elseif (Str::contains($col, 'phone')) {
                            $row[$col] = $this->faker->phoneNumber;
                        } else {
                            $row[$col] = $this->faker->words(3, true);
                        }
                        break;

                    case 'datetime':
                    case 'datetimetz':
                    case 'timestamp':
                        $row[$col] = $this->faker->dateTimeBetween('-1 year', 'now')->format('Y-m-d H:i:s');
                        break;

                    case 'date':
                        $row[$col] = $this->faker->date('Y-m-d');
                        break;

                    case 'boolean':
                        $row[$col] = $this->faker->boolean;
                        break;

                    case 'integer':
                    case 'bigint':
                    case 'smallint':
                        $row[$col] = $this->faker->numberBetween(1, 1000);
                        break;

                    case 'float':
                    case 'decimal':
                        $row[$col] = $this->faker->randomFloat(2, 0, 1000);
                        break;

                    default:
                        if (Str::endsWith($col, '_at')) {
                            $row[$col] = $this->faker->dateTimeBetween('-1 year', 'now')->format('Y-m-d H:i:s');
                        } else {
                            $row[$col] = $this->faker->word;
                        }
                }
            }

            // Set timestamps if present
            if (in_array('created_at', $columns)) {
                $row['created_at'] = now();
            }
            if (in_array('updated_at', $columns)) {
                $row['updated_at'] = now();
            }
            if (in_array('deleted_at', $columns)) {
                $row['deleted_at'] = null;
            }

            $rows[] = $row;
        }

        DB::table($table)->insert($rows);

        $this->info("Inserted {$count} rows into {$table}.");
    }

    protected function showTableDataMenu(string $table, int $limit = 20) {
        $columns = Schema::getColumnListing($table);
        $rows = DB::table($table)->limit($limit)->get();

        if ($rows->isEmpty()) {
            $this->info("Table '{$table}' is empty.");
            sleep(2);
            $this->showTablesMenu();
            return;
        }

        $summaryCols = ['id'];
        foreach (['name', 'email', 'title'] as $col) {
            if (in_array($col, $columns)) {
                $summaryCols[] = $col;
                if (count($summaryCols) >= 3) {
                    break;
                }
            }
        }
        if (count($summaryCols) < 3) {
            foreach ($columns as $col) {
                if (!in_array($col, $summaryCols)) {
                    $summaryCols[] = $col;
                    if (count($summaryCols) >= 3) {
                        break;
                    }
                }
            }
        }

        $menuBuilder = new CliMenuBuilder();
        $menuBuilder->setTitle("Rows in table: {$table} (showing up to {$limit})");

        foreach ($rows as $row) {

            $summaryParts = [];
            foreach ($summaryCols as $col) {
                $val = $row->$col ?? '';
                if (is_string($val) && strlen($val) > 15) {
                    $val = substr($val, 0, 15) . '...';
                }
                $summaryParts[] = "{$col}: {$val}";
            }
            $summary = implode(' | ', $summaryParts);

            $menuBuilder->addItem($summary, function (CliMenu $menu) use ($row, $columns) {
                $menu->close();
                $this->showRowDetailsMenu((array) $row, $columns);
            });
        }

        $menuBuilder->addLineBreak('-');
        $menuBuilder->addItem('Back', function (CliMenu $menu) {
            $menu->close();
            $this->showTablesMenu();
        });

        $menu = $menuBuilder->build();
        $menu->open();
    }

    protected function showRowDetailsMenu(array $row, array $columns) {
        $menuBuilder = new CliMenuBuilder();
        $menuBuilder->setTitle("Row Details");

        foreach ($columns as $col) {
            $val = $row[$col];
            if (is_null($val)) {
                $val = 'NULL';
            } elseif (is_string($val) && strlen($val) > 80) {
                $val = substr($val, 0, 80) . '...';
            }
            $menuBuilder->addStaticItem("{$col}: {$val}");
        }

        $menuBuilder->addLineBreak('-');
        $menuBuilder->addItem('Back', function (CliMenu $menu) {
            $menu->close();
            $this->showTablesMenu();
        });

        $menu = $menuBuilder->build();
        $menu->open();
    }

}
