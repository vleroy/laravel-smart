<?php

namespace Deiucanta\Smart;

class MigrationGenerator extends Generator
{
    public function print($up, $down, $name)
    {
        return $this->joinTree([
            '<?php',
            '',
            "use Illuminate\Support\Facades\Schema;",
            "use Illuminate\Database\Schema\Blueprint;",
            "use Illuminate\Database\Migrations\Migration;",
            '',
            "class $name extends Migration",
            '{',
            $this->joinSections([
                $this->printMethod('up', $up),
                $this->printMethod('down', $down),
            ]),
            '}',
        ]);
    }

    /**
     * Array $data Array that represents the models to migrate
     */
    protected function printMethod($name, $data)
    {
        // $data => ["created" => $table => ["created" => ["field_1" => ["type" => "increments"]], ..., "connection" => "db_api"]
        return [
            "public function {$name}()",
            '{',
            $this->joinSections([
                $this->printCreatedModels($data),
                $this->printUpdatedModels($data),
                $this->printModelsRelationships($data),
                $this->printDeletedModels($data),
            ]),
            '}',
        ];
    }

    protected function printCreatedModels($data)
    {
        if (!isset($data['created'])) {
            return;
        }

        $output = [];

        foreach ($data['created'] as $table => $fields) {
            $output[] = [
                "Schema::connection('{$fields['connection']}')->create('{$table}', function (Blueprint \$table) {",
                $this->printFields($fields),
                '});',
            ];
        }

        return $this->joinSections($output);
    }

    protected function printUpdatedModels($data)
    {
        if (!isset($data['updated'])) {
            return;
        }

        $output = [];

        foreach ($data['updated'] as $table => $fields) {
            $output[] = [
                "Schema::connection('{$fields['connection']}')->table('{$table}', function (Blueprint \$table) {",
                $this->printFields($fields),
                '});',
            ];
        }

        return $this->joinSections($output);
    }

    protected function printModelsRelationships($data)
    {
        $output = [];
        $modelsData = [];

        if (isset($data['created'])) {
            $modelsData = array_merge($modelsData, $data['created']);
        }

        if (isset($data['updated'])) {
            $modelsData = array_merge($modelsData, $data['updated']);
        }

        foreach ($modelsData as $table => $fields) {
            $out = $this->printFieldsRelationships($fields);

            if (!empty($out)) {
                array_push($output,
                    "Schema::connection('{$fields['connection']}')->table('{$table}', function (Blueprint \$table) {",
                    $out,
                    '});'
                );
            }
        }

        if (!empty($output)) {
            array_unshift($output, '// Adding foreign keys');
        }

        return $output;
    }

    protected function printDeletedModels($data)
    {
        if (!isset($data['deleted'])) {
            return;
        }

        $output = [];

        foreach ($data['deleted'] as $table => $fields) {
            $output[] = [
                "Schema::connection('{$fields['connection']}')->disableForeignKeyConstraints();",
                "Schema::connection('{$fields['connection']}')->drop('{$table}');",
                "Schema::connection('{$fields['connection']}')->enableForeignKeyConstraints();"
            ];
        }

        return $this->joinSections($output);
    }

    protected function printFields($fields)
    {
        $output = [];

        if (isset($fields['created'])) {
            foreach ($fields['created'] as $name => $data) {
                $output[] = $this->printField($name, $data).';';
            }
        }

        if (isset($fields['updated'])) {
            foreach ($fields['updated'] as $name => $data) {
                $output[] = $this->printField($name, $data).'->change();';

                if (isset($data['belongsTo'])) {
                    $output = array_merge($output, $this->printDropForeign([$data['belongsTo']['foreignKey']], $fields["connection"]));
                }
            }
        }

        if (isset($fields['deleted'])) {
            foreach ($fields['deleted'] as $name => $field) {
                $relationships = $this->printFieldDeleteRelationships($name, $field, $fields["connection"]);
                foreach ($relationships as $out) {
                    $output[] = $out;
                }
                $output[] = "\$table->dropColumn('{$name}');";
            }
        }

        return $output;
    }

    protected function printFieldsRelationShips($fields)
    {
        $output = [];

        if (isset($fields['created'])) {
            foreach ($fields['created'] as $name => $data) {
                $out = "{$this->printFieldRelationships($name, $data)}";
                if (!empty($out)) {
                    $output[] = $out;
                }
            }
        }

        if (isset($fields['updated'])) {
            foreach ($fields['updated'] as $name => $data) {
                $out = "{$this->printFieldRelationships($name, $data)}";
                if (!empty($out)) {
                    $output[] = $out;
                }
            }
        }

        return $output;
    }

    protected function printField($name, $data)
    {
        $args = isset($data['typeArgs']) ? $data['typeArgs'] : [];

        if (isset($data['belongsTo'])) {
            $name = $data['belongsTo']['foreignKey'];
        }

        $output = '$table->'.$this->printFieldType($data['type'], $name, $args);

        if (isset($data['index'])) {
            $output .= '->index('.json_encode($data['index']).')';
        }
        if (isset($data['unique'])) {
            $output .= '->unique('.json_encode($data['unique']).')';
        }
        if (isset($data['default'])) {
            $output .= '->default('.json_encode($data['default']).')';
        }
        if(isset($data['rawDefault'])) {
            $output .= '->default('.$data['rawDefault'].')';
        }
        if (isset($data['primary'])) {
            $output .= '->primary('.json_encode($data['primary']).')';
        }
        if (isset($data['unsigned'])) {
            $output .= '->unsigned('.json_encode($data['unsigned']).')';
        }
        if (isset($data['nullable'])) {
            $output .= '->nullable('.json_encode($data['nullable']).')';
        }
        if (isset($data['useCurrent'])) {
            $output .= '->useCurrent()';
        }

        return $output;
    }

    protected function printFieldType($type, $name, $args)
    {
        array_unshift($args, $name);

        return $type.'('.implode(', ', array_map('json_encode', $args)).')';
    }

    protected function printFieldRelationships($name, $data)
    {
        $output = '';

        if (isset($data['belongsTo'])) {
            $relation = $data['belongsTo'];
            $otherModel = new $relation['model']();
            $output .= sprintf("\$table->foreign('%s')->references('%s')->on('%s');", $relation['foreignKey'], $otherModel->getPrimaryKey(), $otherModel->getTable());
        }

        return $output;
    }

    protected function printFieldDeleteRelationships($name, $field, $connection)
    {
        $foreignKeys = [];

        if (isset($field['belongsTo'])) {
            $foreignKeys[] = $field['belongsTo']['foreignKey'];
        }

        if (!empty($foreignKeys)) {
            return $this->printDropForeign($foreignKeys, $connection);
        }

        return [];
    }

    protected function printDropForeign($foreignKeys, $connection)
    {
        $keys = implode($foreignKeys, '\', \'');

        return [
            "Schema::connection('{$connection}')->disableForeignKeyConstraints();",
            "\$table->dropForeign(['{$keys}']);",
            "Schema::connection('{$connection}')->enableForeignKeyConstraints();"
        ];
    }
}
