# ProcessDbBackup migrations

Place Git-tracked ProcessWire deployment migrations in this folder. Files can be written manually or generated from the **DB Backup -> Migrations** admin screen.

Migration files must use a safe filename such as:

```text
2026_06_21_1530_recipes.php
```

Each file is executed once from the **DB Backup -> Migrations** admin screen. The module exposes common ProcessWire API variables to the file:

```php
<?php namespace ProcessWire;

/** @var ProcessWire $wire */
/** @var Fields $fields */
/** @var Templates $templates */
/** @var Pages $pages */
/** @var Modules $modules */

$field = $fields->get('recipe_time');
if (!$field->id) {
	$field = new Field();
	$field->name = 'recipe_time';
	$field->type = $modules->get('FieldtypeInteger');
	$field->label = 'Recipe time';
	$fields->save($field);
}

$template = $templates->get('recipe');
if ($template->id && !$template->fieldgroup->hasField($field)) {
	$template->fieldgroup->add($field);
	$fieldgroups->save($template->fieldgroup);
}

return 'Recipe fields migrated.';
```

Keep migrations idempotent where possible: check whether a field, template, page, role, or permission exists before creating or changing it.
