<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2020, Julien Veyssier
 * @copyright Copyright (c) 2026, Kim Haverblad (fork maintainer)
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program. If not, see <http://www.gnu.org/licenses/>
 *
 * @Code Changes by: Kim Haverblad, 2026
 */
namespace OCA\SuiteCRM\Search;

use OCP\Search\SearchResultEntry;

/**
 * Named subclass of {@see SearchResultEntry} used by
 * {@see SuiteCRMSearchProvider::search()} when constructing rows for the
 * Nextcloud unified-search response. The parent class is instantiable on
 * its own — this subclass exists purely to give the type a fork-owned
 * name so IDE navigation and PHPStan analysis stay inside the app's
 * namespace instead of jumping into `OCP\Search`. Empty body is
 * intentional.
 */
class SuiteCRMSearchResultEntry extends SearchResultEntry {
}