<?php

declare(strict_types=1);

namespace Modules\Catalog\Models;

/**
 * The legacy latex_template contract, separate from file API latex_templates.
 *
 * @property int $id
 * @property string $title
 * @property string|null $description
 * @property string $latex_source
 * @property string|null $pdf_path
 */
class LatexTemplate extends CatalogModel
{
    protected $table = 'latex_template';
}
