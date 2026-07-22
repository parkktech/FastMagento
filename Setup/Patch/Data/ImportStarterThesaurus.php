<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Setup\Patch\Data;

use Magento\Framework\Module\Dir;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use ParkkTech\FastMagento\Model\Search\SynonymImporter;

/**
 * Imports the bundled starter synonym/thesaurus database (etc/thesaurus/starter-synonyms.json)
 * into the Search > Synonyms setting on install, so a fresh extension install ships with a
 * usable synonym baseline out of the box — before the merchant ever runs the AI tools.
 *
 * Merge-safe (SynonymImporter unions, never clobbers), so it coexists with the config.xml
 * defaults and with any hand-curated or AI-generated groups. Runs once (tracked by class name
 * in patch_list); the AI thesaurus tool augments it per-store afterwards.
 */
class ImportStarterThesaurus implements DataPatchInterface
{
    public function __construct(
        private readonly Dir $moduleDir,
        private readonly SynonymImporter $synonymImporter
    ) {
    }

    public function apply(): self
    {
        $file = $this->moduleDir->getDir('ParkkTech_FastMagento', Dir::MODULE_ETC_DIR)
            . '/thesaurus/starter-synonyms.json';

        $raw = is_readable($file) ? (string) file_get_contents($file) : '';
        if ($raw === '') {
            return $this;   // nothing to import; leave config.xml defaults in place
        }

        $decoded = json_decode($raw, true);
        $groups = is_array($decoded) && isset($decoded['groups']) && is_array($decoded['groups'])
            ? $decoded['groups']
            : [];
        if ($groups) {
            $this->synonymImporter->merge($groups);
        }

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @return array<int, string>
     */
    public function getAliases(): array
    {
        return [];
    }
}
