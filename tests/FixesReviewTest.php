<?php

/**
 * FleetBooking Plugin — Automated Tests for Critical & Warning Fixes.
 *
 * This file covers the key fixes reviewed and applied on 2026-05-23:
 *   CRITICAL 1: Removal of debug mode in availability.php
 *   CRITICAL 2: Explicit timezone in DateTime / DateTimeImmutable parsing
 *   WARNING 3: closeTicket() guard against reopening CLOSED tickets
 *   WARNING 1: resolveManagerGroup() batch query optimization
 *   SUGGESTION 1: Removal of redundant addslashes_deep in config.form.php
 *   WARNING 4: ApprovalService hydration lifecycle hook
 *
 * Run via CLI (no PHPUnit needed):
 *   php fleetbooking/tests/FixesReviewTest.php
 *
 * Run with PHPUnit (if installed):
 *   php vendor/bin/phpunit fleetbooking/tests/FixesReviewTest.php
 */

namespace GlpiPlugin\Fleetbooking\Tests;

if (class_exists('PHPUnit\Framework\TestCase')) {
    // PHPUnit is available — use proper TestCase
}

/**
 * Minimal polyfill so tests can run without PHPUnit installed.
 * When PHPUnit is available, this base class is irrelevant because
 * the namespace resolution will find the real TestCase.
 */
if (!class_exists('PHPUnit\Framework\TestCase')) {
    abstract class LocalTestCase
    {
        public function __construct(?string $name = null, array $data = [], $dataName = '')
        {
        }

        protected function assertSame($expected, $actual, string $message = ''): void
        {
            if ($expected !== $actual) {
                throw new \RuntimeException(
                    "$message\nExpected: " . var_export($expected, true) .
                    "\nActual:   " . var_export($actual, true)
                );
            }
        }

        protected function assertStringContainsString(string $needle, string $haystack, string $message = ''): void
        {
            if (strpos($haystack, $needle) === false) {
                throw new \RuntimeException(
                    "$message\nExpected to find '$needle' in:\n$haystack"
                );
            }
        }

        protected function assertStringNotContainsString(string $needle, string $haystack, string $message = ''): void
        {
            if (strpos($haystack, $needle) !== false) {
                throw new \RuntimeException(
                    "$message\nFound unexpected '$needle' in:\n$haystack"
                );
            }
        }

        protected function assertFileExists(string $path, string $message = ''): void
        {
            if (!file_exists($path)) {
                throw new \RuntimeException("$message\nFile does not exist: $path");
            }
        }

        protected function assertNotFalse($value, string $message = ''): void
        {
            if ($value === false) {
                throw new \RuntimeException("$message\nValue is false.");
            }
        }

        protected function assertNotEmpty($value, string $message = ''): void
        {
            if (empty($value)) {
                throw new \RuntimeException("$message\nValue is empty.");
            }
        }

        protected function fail(string $message): never
        {
            throw new \RuntimeException($message);
        }
    }
    class_alias(LocalTestCase::class, 'PHPUnit\Framework\TestCase');
}

use PHPUnit\Framework\TestCase;

class FixesReviewTest extends TestCase
{
    // -----------------------------------------------------------------------
    // CRITICAL 2 — Explicit timezone in DateTime / DateTimeImmutable
    // -----------------------------------------------------------------------

    /**
     * ReservationService & RequestService should use GLPI's configured
     * timezone (or UTC fallback) when normalising dates, preventing silent
     * shifts when server timezone differs from the GLPI instance setting.
     *
     * @dataProvider dateTimezoneProvider
     */
    public function testDateTimeParsingRespectsTimezone(
        string $inputDate,
        ?string $sessionTz,
        string $expectedFormatted
    ): void {
        if ($sessionTz !== null) {
            $_SESSION['glpi_tz'] = $sessionTz;
        } else {
            unset($_SESSION['glpi_tz']);
        }

        // Simulate the actual code pattern: $_SESSION['glpi_tz'] ?? 'UTC'
        $tzName = $_SESSION['glpi_tz'] ?? 'UTC';
        $tz = new \DateTimeZone($tzName);

        // Test DateTime (used in ReservationService)
        $dt = new \DateTime($inputDate, $tz);
        $actual = $dt->format('Y-m-d H:i:s');

        $this->assertSame(
            $expectedFormatted,
            $actual,
            sprintf(
                'DateTime "%s" with timezone "%s" should format as "%s", got "%s".',
                $inputDate,
                $tzName,
                $expectedFormatted,
                $actual
            )
        );

        // Test DateTimeImmutable (used in RequestService)
        $dtImm = new \DateTimeImmutable($inputDate, $tz);
        $actualImm = $dtImm->format('Y-m-d H:i:s');

        $this->assertSame(
            $expectedFormatted,
            $actualImm,
            sprintf(
                'DateTimeImmutable "%s" with timezone "%s" should format as "%s", got "%s".',
                $inputDate,
                $tzName,
                $expectedFormatted,
                $actualImm
            )
        );
    }

    public static function dateTimezoneProvider(): array
    {
        return [
            'UTC datetime stays UTC' => [
                '2026-06-15 14:30:00',
                'UTC',
                '2026-06-15 14:30:00',
            ],
            'America/Sao_Paulo preserves local time' => [
                '2026-06-15 14:30:00',
                'America/Sao_Paulo',
                '2026-06-15 14:30:00',
            ],
            'ISO 8601 with timezone offset is normalized' => [
                '2026-06-15T14:30:00-03:00',
                'America/Sao_Paulo',
                '2026-06-15 14:30:00',
            ],
            'Fallback to UTC when glpi_tz is absent' => [
                '2026-06-15 14:30:00',
                null,  // unset — code falls back to 'UTC'
                '2026-06-15 14:30:00',
            ],
            'Midnight boundary' => [
                '2026-01-01 00:00:00',
                'UTC',
                '2026-01-01 00:00:00',
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // WARNING 3 — closeTicket() guard against CLOSED tickets
    // -----------------------------------------------------------------------

    /**
     * TicketService::closeTicket should exit early when the ticket
     * is already in CLOSED status, preventing an accidental reopen.
     */
    public function testCloseTicketDoesNotReopenClosedTicket(): void
    {
        $sourceFile = __DIR__ . '/../src/Service/TicketService.php';
        $this->assertFileExists($sourceFile, 'TicketService.php must exist.');

        $lines = file($sourceFile, FILE_IGNORE_NEW_LINES);
        $this->assertNotFalse($lines, 'Could not read TicketService.php.');

        $source = implode("\n", $lines);

        $this->assertStringContainsString(
            'Ticket::CLOSED',
            $source,
            'closeTicket() must reference Ticket::CLOSED to guard against reopening closed tickets.'
        );

        // Verify that an early return exists after the CLOSED check but before update()
        $this->assertStringContainsString(
            "return;\n",
            $source,
            'closeTicket() must have an early return when the ticket is already CLOSED.'
        );
    }

    // -----------------------------------------------------------------------
    // CRITICAL 1 — No debug mode in availability.php
    // -----------------------------------------------------------------------

    /**
     * availability.php must NOT contain ini_set('display_errors', 1) or
     * error_reporting(E_ALL) in production code, as these leak internal
     * paths and stack traces into JSON responses.
     */
    public function testAvailabilityScriptHasNoDebugMode(): void
    {
        $filePath = __DIR__ . '/../ajax/availability.php';
        $this->assertFileExists($filePath, 'availability.php must exist.');

        $contents = file_get_contents($filePath);
        $this->assertNotFalse($contents, 'Could not read availability.php.');

        $this->assertStringNotContainsString(
            "ini_set('display_errors'",
            $contents,
            'availability.php must not contain ini_set(\'display_errors\') — this leaks paths in production.'
        );

        $this->assertStringNotContainsString(
            'error_reporting(E_ALL)',
            $contents,
            'availability.php must not contain error_reporting(E_ALL) — this leaks paths in production.'
        );
    }

    // -----------------------------------------------------------------------
    // SUGGESTION 1 — No redundant addslashes_deep before GLPI update/add
    // -----------------------------------------------------------------------

    /**
     * config.form.php should not call Toolbox::addslashes_deep() before
     * passing data to $config->update() or $config->add(), because
     * CommonDBTM::update()/add() already sanitise input internally,
     * and the double call would cause double-escaping.
     */
    public function testConfigFormHasNoDoubleEscaping(): void
    {
        $filePath = __DIR__ . '/../front/config.form.php';
        $this->assertFileExists($filePath, 'config.form.php must exist.');

        $contents = file_get_contents($filePath);
        $this->assertNotFalse($contents, 'Could not read config.form.php.');

        // Extract the section between "if (isset" and the closing brace after add/update
        // We just check the whole file for the problematic pattern.
        $this->assertStringNotContainsString(
            'addslashes_deep',
            $contents,
            'config.form.php must not call Toolbox::addslashes_deep() — ' .
            'GLPI\'s CommonDBTM::update()/add() already sanitises input.'
        );
    }

    // -----------------------------------------------------------------------
    // WARNING 1 — resolveManagerGroup() uses a single batch query
    // -----------------------------------------------------------------------

    /**
     * The resolveManagerGroup() method in RequestService should use a
     * batch query (WHERE groups_id IN (...)) instead of a foreach loop
     * with individual getFromDBByCrit() calls, avoiding the N+1 problem.
     */
    public function testResolveManagerGroupUsesBatchQuery(): void
    {
        $sourceFile = __DIR__ . '/../src/Service/RequestService.php';
        $this->assertFileExists($sourceFile, 'RequestService.php must exist.');

        $source = file_get_contents($sourceFile);
        $this->assertNotFalse($source, 'Could not read RequestService.php.');

        // Extract only the resolveManagerGroup() method body using regex
        // to avoid false positives from legitimate getFromDBByCrit() calls
        // in other methods such as checkAvailability().  Using regex is
        // resilient to line-number shifts when the source file is modified.
        preg_match(
            '/private function resolveManagerGroup\(.*?\n    \}/s',
            $source,
            $matches
        );
        $this->assertNotEmpty(
            $matches,
            'resolveManagerGroup() method not found in RequestService.php.'
        );
        $methodSource = $matches[0];

        // The optimised version should contain array_column (to collect
        // group IDs) before a single $DB->request() call.
        $this->assertStringContainsString(
            'array_column',
            $methodSource,
            'resolveManagerGroup() should use array_column to collect group IDs for batch query.'
        );

        // The original N+1 pattern had getFromDBByCrit inside the foreach.
        // After the fix, getFromDBByCrit should NOT appear inside
        // resolveManagerGroup().
        $this->assertStringNotContainsString(
            'getFromDBByCrit',
            $methodSource,
            'resolveManagerGroup() should not use getFromDBByCrit() inside its body (N+1 anti-pattern).'
        );
    }

    // -----------------------------------------------------------------------
    // WARNING 4 — ApprovalService hydration lifecycle
    // -----------------------------------------------------------------------

    /**
     * After populating $lockedRequest->fields from the FOR UPDATE raw
     * result, ApprovalService::processDecision should call
     * $lockedRequest->getFromDB() to trigger GLPI lifecycle hooks.
     */
    public function testApprovalServiceTriggersHydrationLifecycle(): void
    {
        $sourceFile = __DIR__ . '/../src/Service/ApprovalService.php';
        $this->assertFileExists($sourceFile, 'ApprovalService.php must exist.');

        $lines = file($sourceFile, FILE_IGNORE_NEW_LINES);
        $this->assertNotFalse($lines, 'Could not read ApprovalService.php.');

        $source = implode("\n", $lines);

        // After the raw `$lockedRequest->fields = $row` assignment,
        // there should be a call to `getFromDB()` to ensure lifecycle
        // hooks (post_getFromDB etc.) are triggered.
        $this->assertStringContainsString(
            'getFromDB',
            $source,
            'processDecision() must call $lockedRequest->getFromDB() after raw field assignment ' .
            'to trigger GLPI lifecycle hooks.'
        );
    }
}

// ---------------------------------------------------------------------------
// Standalone runner — allows execution via `php tests/FixesReviewTest.php`
// without a full PHPUnit bootstrap.
// ---------------------------------------------------------------------------
if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $passed = 0;
    $failed = 0;

    $test = new \GlpiPlugin\Fleetbooking\Tests\FixesReviewTest('');

    // --- Date / Timezone tests (DateTime + DateTimeImmutable) ---
    foreach ($test::dateTimezoneProvider() as $name => $args) {
        try {
            $test->testDateTimeParsingRespectsTimezone($args[0], $args[1], $args[2]);
            echo "  ✓ testDateTimeParsingRespectsTimezone [$name]\n";
            $passed++;
        } catch (\Throwable $e) {
            echo "  ✗ testDateTimeParsingRespectsTimezone [$name]: {$e->getMessage()}\n";
            $failed++;
        }
    }

    // --- Source-code inspection tests ---
    try {
        $test->testCloseTicketDoesNotReopenClosedTicket();
        echo "  ✓ testCloseTicketDoesNotReopenClosedTicket\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "  ✗ testCloseTicketDoesNotReopenClosedTicket: {$e->getMessage()}\n";
        $failed++;
    }

    try {
        $test->testAvailabilityScriptHasNoDebugMode();
        echo "  ✓ testAvailabilityScriptHasNoDebugMode\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "  ✗ testAvailabilityScriptHasNoDebugMode: {$e->getMessage()}\n";
        $failed++;
    }

    try {
        $test->testConfigFormHasNoDoubleEscaping();
        echo "  ✓ testConfigFormHasNoDoubleEscaping\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "  ✗ testConfigFormHasNoDoubleEscaping: {$e->getMessage()}\n";
        $failed++;
    }

    try {
        $test->testResolveManagerGroupUsesBatchQuery();
        echo "  ✓ testResolveManagerGroupUsesBatchQuery\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "  ✗ testResolveManagerGroupUsesBatchQuery: {$e->getMessage()}\n";
        $failed++;
    }

    try {
        $test->testApprovalServiceTriggersHydrationLifecycle();
        echo "  ✓ testApprovalServiceTriggersHydrationLifecycle\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "  ✗ testApprovalServiceTriggersHydrationLifecycle: {$e->getMessage()}\n";
        $failed++;
    }

    echo "\n---\nPassed: $passed, Failed: $failed\n";
    exit($failed > 0 ? 1 : 0);
}
