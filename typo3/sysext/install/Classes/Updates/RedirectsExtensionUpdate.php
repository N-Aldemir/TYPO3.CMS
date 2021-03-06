<?php
declare(strict_types = 1);
namespace TYPO3\CMS\Install\Updates;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Installs EXT:redirect if sys_domain.redirectTo is filled, and migrates the values from redirectTo
 * to a proper sys_redirect entry.
 */
class RedirectsExtensionUpdate extends AbstractDownloadExtensionUpdate implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var string
     */
    protected $title = 'Install system extension "redirects" if a sys_domain entry with redirectTo is necessary';

    /**
     * @var array
     */
    protected $extensionDetails = [
        'redirects' => [
            'title' => 'Redirects',
            'description' => 'Manage redirects for your TYPO3-based website',
            'versionString' => '9.2',
            'composerName' => 'typo3/cms-redirects',
        ],
    ];

    /**
     * Checks if an update is needed
     *
     * @param string $description The description for the update
     * @return bool Whether an update is needed (true) or not (false)
     */
    public function checkForUpdate(&$description): bool
    {
        $description = 'The extension "redirects" includes functionality to handle any kind of redirects. '
            . 'The functionality superseds sys_domain entries with the only purpose of redirecting to a different domain or entry. '
            . 'This upgrade wizard installs the redirect extension if necessary and migrates the sys_domain entries to standard redirects.';

        $updateNeeded = false;

        // Check if table exists and table is not empty, and the wizard has not been run already
        if ($this->checkIfWizardIsRequired() && !$this->isWizardDone()) {
            $updateNeeded = true;
        }

        return $updateNeeded;
    }

    /**
     * Performs the update:
     * - Install EXT:redirect
     * - Migrate DB records
     *
     * @param array $databaseQueries Queries done in this update
     * @param string $customMessage Custom message
     * @return bool
     */
    public function performUpdate(array &$databaseQueries, &$customMessage): bool
    {
        // Install the EXT:redirects extension if not happened yet
        $installationSuccessful = $this->installExtension('redirects', $customMessage);
        if ($installationSuccessful) {
            // Migrate the database entries
            $this->migrateRedirectDomainsToSysRedirect();
            $this->markWizardAsDone();
        }
        return $installationSuccessful;
    }

    /**
     * Check if the database field "sys_domain.redirectTo" exists and if so, if there are entries in the DB table with the field filled.
     *
     * @return bool
     * @throws \InvalidArgumentException
     */
    protected function checkIfWizardIsRequired(): bool
    {
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $connection = $connectionPool->getConnectionByName('Default');
        $columns = $connection->getSchemaManager()->listTableColumns('sys_domain');
        if (isset($columns['redirectto'])) {
            // table is available, now check if there are entries in it
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getQueryBuilderForTable('sys_domain');
            $queryBuilder->getRestrictions()->removeAll();
            $numberOfEntries = $queryBuilder->count('*')
                ->from('sys_domain')
                ->where(
                    $queryBuilder->expr()->neq('redirectTo', $queryBuilder->createNamedParameter('', \PDO::PARAM_STR))
                )
                ->execute()
                ->fetchColumn();
            return (bool)$numberOfEntries;
        }

        return false;
    }

    /**
     * Move all sys_domain records with a "redirectTo" value filled (also deleted) to "sys_redirect" record
     */
    protected function migrateRedirectDomainsToSysRedirect()
    {
        $connDomains = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_domain');
        $connRedirects = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_redirect');

        $queryBuilder = $connDomains->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();
        $domainEntries = $queryBuilder->select('*')
            ->from('sys_domain')
            ->where(
                $queryBuilder->expr()->neq('redirectTo', $queryBuilder->createNamedParameter('', \PDO::PARAM_STR))
            )
            ->execute()
            ->fetchAll();

        foreach ($domainEntries as $domainEntry) {
            $domainName = $domainEntry['domainName'];
            $target = $domainEntry['redirectTo'];
            $sourceDetails = parse_url($domainName);
            $targetDetails = parse_url($target);
            $redirectRecord = [
                'deleted' => (int)$domainEntry['deleted'],
                'disabled' => (int)$domainEntry['hidden'],
                'createdon' => (int)$domainEntry['crdate'],
                'createdby' => (int)$domainEntry['cruser_id'],
                'updatedon' => (int)$domainEntry['tstamp'],
                'source_host' => $sourceDetails['host'] . ($sourceDetails['port'] ? ':' . $sourceDetails['port'] : ''),
                'keep_query_parameters' => (int)$domainEntry['prepend_params'],
                'target_statuscode' => (int)$domainEntry['redirectHttpStatusCode'],
                'target' => $target
            ];

            if (isset($targetDetails['scheme']) && $targetDetails['scheme'] === 'https') {
                $redirectRecord['force_https'] = 1;
            }

            if (empty($sourceDetails['path']) || $sourceDetails['path'] === '/') {
                $redirectRecord['source_path'] = '.*';
                $redirectRecord['is_regexp'] = 1;
            } else {
                // Remove the / and add a "/" always before, and at the very end, if path is not empty
                $sourceDetails['path'] = trim($sourceDetails['path'], '/');
                $redirectRecord['source_path'] = '/' . ($sourceDetails['path'] ? $sourceDetails['path'] . '/' : '');
            }

            // Add the redirect record
            $connRedirects->insert('sys_redirect', $redirectRecord);

            // Remove the sys_domain record (hard)
            $connDomains->delete('sys_domain', ['uid' => (int)$domainEntry['uid']]);
        }
    }
}
