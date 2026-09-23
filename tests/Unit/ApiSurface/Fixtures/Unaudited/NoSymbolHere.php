<?php

declare(strict_types=1);

// Fixture: a source file whose name promises a class it does not declare.
// The surface walk must refuse it rather than contribute zero rows.

namespace Gisl\Sdk\Tests\Unit\ApiSurface\Fixtures\Unaudited;

// Deliberately declares nothing: a declaration here would be re-included
// by every run of the walk and warn on redefinition.
