<?php

declare(strict_types=1);

namespace OCA\ContactHub\Files;

use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;

/**
 * The app's folder inside a user's own Files: "Contacts Hub".
 *
 * Everything this app produces that a person might want to keep, inspect or
 * carry elsewhere lives here rather than in IAppData. IAppData is invisible:
 * it sits outside every user's home, the Files app cannot see it, and there
 * is no way to download from it without an app route written for the purpose.
 * That is right for caches and wrong for a backup, whose entire value is
 * being able to get at it when something has gone wrong.
 *
 * Putting it in the user's home also means the quota, the trash, versioning,
 * the desktop sync client and the deletion of the user's account all apply
 * without this app implementing any of them.
 *
 *     Contacts Hub/
 *       Snapshots/          address book snapshots (.json.gz)
 *       Settings/           exported endpoint and job configuration (.json)
 *       Archived contacts/  single contacts (.vcf) archived from a conflict
 *                           resolution, before being overwritten
 *
 * The trade-off, and it is a real one: the user can now delete or edit these
 * files. Callers must treat a missing or unparseable file as an ordinary
 * outcome rather than an invariant violation.
 */
class HubFolder
{
    public const string ROOT = 'Contacts Hub';
    public const string SNAPSHOTS = 'Snapshots';
    public const string SETTINGS = 'Settings';
    public const string ARCHIVED_CONTACTS = 'Archived contacts';

    public function __construct(private readonly IRootFolder $rootFolder)
    {
    }

    /**
     * The named subfolder, creating it and its parent.
     *
     * Only for writing. Reads and deletes go through existingFolder(), because
     * creating a folder as a side effect of looking for a file in it means an
     * empty "Contacts Hub" appearing in the Files of someone who never used
     * the feature -- which is what pruning a pre-move snapshot used to do.
     *
     * @throws NotPermittedException when the user's home is not writable, and
     *     NoUserException when there is no such account.
     */
    public function folder(string $userId, string $sub): Folder
    {
        $home = $this->rootFolder->getUserFolder($userId);

        return $this->childFolder($this->childFolder($home, self::ROOT), $sub);
    }

    /**
     * The named subfolder if it is already there, otherwise null. Never
     * creates anything, and never throws.
     *
     * The catch is deliberately \Throwable rather than the Files exception
     * types. `IRootFolder::getUserFolder()` throws `OC\User\NoUserException`
     * for an account that no longer exists, and that extends plain \Exception,
     * so none of the Files types cover it. It escaped from delete() and took
     * out the whole of BackupService::prune() with it -- for every user, not
     * just the departed one -- because UserDeletedEvent fires *after* the
     * account is gone, making that the ordinary path rather than a race.
     *
     * Nothing here is load-bearing enough to be worth distinguishing: every
     * caller wants "the folder, or nothing".
     */
    private function existingFolder(string $userId, string $sub): ?Folder
    {
        try {
            $home = $this->rootFolder->getUserFolder($userId);
            foreach ([self::ROOT, $sub] as $name) {
                if (!$home->nodeExists($name)) {
                    return null;
                }
                $node = $home->get($name);
                if (!$node instanceof Folder) {
                    return null;
                }
                $home = $node;
            }

            return $home;
        } catch (\Throwable) {
            return null;
        }
    }

    private function childFolder(Folder $parent, string $name): Folder
    {
        try {
            $node = $parent->get($name);
            if ($node instanceof Folder) {
                return $node;
            }
            // Something that is not a folder is sitting on the name. Renaming
            // or deleting a user's file to make room would be well outside
            // what this app may do on its own.
            throw new NotPermittedException(
                "\"{$parent->getPath()}/{$name}\" exists but is a file, not a folder. "
                . 'Rename or move it and try again.',
            );
        } catch (NotFoundException) {
            return $parent->newFolder($name);
        }
    }

    /**
     * Write a file, returning the path relative to the user's home so it can
     * be shown to them or opened in the Files app.
     */
    public function write(string $userId, string $sub, string $name, string $content): string
    {
        $folder = $this->folder($userId, $sub);

        try {
            $file = $folder->get($name);
            $file->putContent($content);
        } catch (NotFoundException) {
            $file = $folder->newFile($name, $content);
        }

        return self::ROOT . '/' . $sub . '/' . $name;
    }

    /**
     * Whether one file is already there, without creating the folder or
     * listing it. listFiles() would answer this too, but it stats and sorts
     * every node to do it, which is a lot of work to ask one question of a
     * folder that only ever grows.
     */
    public function exists(string $userId, string $sub, string $name): bool
    {
        $folder = $this->existingFolder($userId, $sub);

        return $folder !== null && $folder->nodeExists($name);
    }

    /** @return string|null null when the user has deleted or renamed it. */
    public function read(string $userId, string $sub, string $name): ?string
    {
        try {
            $folder = $this->existingFolder($userId, $sub);
            if ($folder === null || !$folder->nodeExists($name)) {
                return null;
            }
            $content = $folder->get($name)->getContent();
        } catch (\Throwable) {
            return null;
        }

        return $content === false ? null : $content;
    }

    /** Read any file in the user's home by path, for importing a file they picked. */
    public function readUserPath(string $userId, string $path): ?string
    {
        try {
            $content = $this->rootFolder->getUserFolder($userId)->get(ltrim($path, '/'))->getContent();
        } catch (\Throwable) {
            return null;
        }

        return $content === false ? null : $content;
    }

    /**
     * Silent when already gone: the user may have deleted it themselves, or
     * the whole account may be gone, which is exactly when this is called.
     */
    public function delete(string $userId, string $sub, string $name): void
    {
        try {
            $folder = $this->existingFolder($userId, $sub);
            if ($folder !== null && $folder->nodeExists($name)) {
                $folder->get($name)->delete();
            }
        } catch (\Throwable) {
            // Nothing to do.
        }
    }

    /**
     * Files in one subfolder, newest first.
     *
     * @return list<array{name: string, path: string, size: int, modified: int}>
     */
    public function listFiles(string $userId, string $sub): array
    {
        $folder = $this->existingFolder($userId, $sub);
        if ($folder === null) {
            return [];
        }

        try {
            $nodes = $folder->getDirectoryListing();
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($nodes as $node) {
            if ($node instanceof Folder) {
                continue;
            }
            $out[] = [
                'name' => $node->getName(),
                'path' => self::ROOT . '/' . $sub . '/' . $node->getName(),
                'size' => (int) $node->getSize(),
                'modified' => (int) $node->getMTime(),
            ];
        }

        usort($out, static fn(array $a, array $b): int => $b['modified'] <=> $a['modified']);

        return $out;
    }

    /**
     * Make a user-supplied string safe as a single filename component.
     *
     * These names reach a real filesystem through Nextcloud's storage layer,
     * and they come from address book display names, which are free text.
     */
    public static function safeName(string $name, string $fallback = 'untitled'): string
    {
        $clean = preg_replace('/[^\p{L}\p{N} ._-]+/u', '-', $name) ?? '';
        $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? '', ' .-');

        return $clean === '' ? $fallback : mb_substr($clean, 0, 60);
    }
}
