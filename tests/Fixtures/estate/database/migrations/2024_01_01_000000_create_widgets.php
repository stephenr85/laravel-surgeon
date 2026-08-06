<?php

use Vendor\Old\Widget;

// A reference living in a migration is positionally Tier-2 (MigrationReference), even though the
// syntactic node is a plain use-import.
return new class
{
    public function up(): void
    {
        $class = Widget::class;
    }
};
