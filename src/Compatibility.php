<?php

namespace NineteenNinetyFour\Flatpack;

use Composer\InstalledVersions;

/**
 * The parts of Statamic that Flatpack reaches inside of: protected properties it reads or writes,
 * and methods it overrides or calls. They aren't public API, so a Statamic release could rename
 * them. Writing to a renamed property doesn't fail (PHP just adds a new one), so a change would
 * otherwise go unnoticed and leave stale content. Flatpack checks they're all still there when it
 * boots, and if any isn't, it pauses: the site runs on Statamic's own Stache, as without Flatpack.
 *
 * This only checks the names are still there. The parity suite checks they still mean the same.
 * The checks are property_exists/method_exists on classes Statamic loads on every request anyway.
 */
final class Compatibility
{
    /** class => [what Flatpack uses: '$property' or 'method()'] */
    public const REQUIRES = [
        \Statamic\Stache\Stores\Store::class => ['$paths', '$keys', '$fileChangesHandled', 'paths()', 'cachePaths()', 'forgetPath()', 'resolveIndexes()', 'handleFileChanges()', 'getItem()', 'directory()'],
        \Statamic\Stache\Stores\BasicStore::class => ['forgetItem()'],
        \Statamic\Stache\Stores\CollectionEntriesStore::class => ['collection()', 'getItemFilter()'],
        \Statamic\Stache\Indexes\Index::class => ['$items', 'items()', 'load()', 'updateItem()', 'update()', 'cache()', 'isCached()', 'forgetItem()'],
        \Statamic\Stache\Traverser::class => ['$filter', 'traverse()', 'filter()'],
        \Statamic\Entries\Entry::class => ['$data', 'data()', 'routeData()', 'getCurrentDirtyStateAttributes()', 'origin()', '__sleep()'],
        \Statamic\Yaml\Yaml::class => ['parse()'],
    ];

    private static ?array $problems = null;

    /** What's missing, as 'Class::$property' or 'Class::method()'. Empty when everything is there. */
    public static function problems(): array
    {
        if (self::$problems !== null) {
            return self::$problems;
        }

        $missing = [];
        foreach (self::REQUIRES as $class => $members) {
            if (! class_exists($class)) {
                $missing[] = $class;
                continue;
            }
            foreach ($members as $member) {
                $found = str_starts_with($member, '$')
                    ? property_exists($class, substr($member, 1))
                    : method_exists($class, substr($member, 0, -2));
                if (! $found) {
                    $missing[] = class_basename($class).'::'.$member;
                }
            }
        }

        return self::$problems = $missing;
    }

    public static function ok(): bool
    {
        return self::problems() === [];
    }

    /** One line for logs, the utility and `flatpack:status`. */
    public static function message(): string
    {
        $version = self::statamicVersion();

        return 'Flatpack is paused: Statamic'.($version ? " {$version}" : '').' has changed '.implode(', ', self::problems())
            .', which Flatpack relies on. The site is running on Statamic\'s own Stache until a Flatpack update supports this version.';
    }

    public static function statamicVersion(): ?string
    {
        try {
            return InstalledVersions::getPrettyVersion('statamic/cms');
        } catch (\Throwable) {
            return null;
        }
    }

    /** For tests. */
    public static function forget(): void
    {
        self::$problems = null;
    }
}
