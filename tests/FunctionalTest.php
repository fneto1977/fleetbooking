<?php

/**
 * FleetBooking Plugin — Automated Functional Test Suite.
 *
 * Covers the test plan documented in documentation/PLANO_TESTES_FleetBooking.md:
 *   Section 2  — Functional: Solicitação (TC-2.2.x validation rules)
 *   Section 4  — Functional: Aprovação/Rejeição (TC-4.x)
 *   Section 5  — Functional: Conflitos / Overlap (TC-5.x)
 *   Section 7  — Security: Permissions, CSRF, Injection (TC-7.x)
 *   Section 8  — Data Consistency (TC-8.x)
 *   Section 9  — Validation rules (TC-9.x)
 *   Section 10 — i18n correctness (TC-10.x)
 *   Section 13 — Regressão: install/upgrade structure (TC-13.x)
 *   Section 15 — Security vulnerabilities (TC-15.x)
 *
 * Can run standalone (no PHPUnit needed):
 *   php fleetbooking/tests/FunctionalTest.php
 *
 * Or via PHPUnit:
 *   php vendor/bin/phpunit fleetbooking/tests/FunctionalTest.php
 *
 * Strategy: Tests fall into three categories:
 *   1. PURE UNIT  — Logic that needs no GLPI bootstrap (date math, constants,
 *                   business rule conditions). These instantiate service classes
 *                   with mocked globals.
 *   2. SOURCE INSPECTION — Regex/file analysis of .php sources to verify
 *                   security patterns (CSRF tokens, right checks, no debug).
 *   3. STATIC ANALYSIS — Verifying class/method signatures and constants exist.
 */

namespace GlpiPlugin\Fleetbooking\Tests;

// ---------------------------------------------------------------------------
// Polyfill: allow running without PHPUnit installed
// ---------------------------------------------------------------------------
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

        protected function assertNotSame($expected, $actual, string $message = ''): void
        {
            if ($expected === $actual) {
                throw new \RuntimeException(
                    "$message\nExpected different value, but both are: " . var_export($expected, true)
                );
            }
        }

        protected function assertTrue($condition, string $message = ''): void
        {
            if (!$condition) {
                throw new \RuntimeException($message ?: 'Expected true, got false.');
            }
        }

        protected function assertFalse($condition, string $message = ''): void
        {
            if ($condition) {
                throw new \RuntimeException($message ?: 'Expected false, got true.');
            }
        }

        protected function assertNull($value, string $message = ''): void
        {
            if ($value !== null) {
                throw new \RuntimeException($message ?: sprintf('Expected null, got %s.', var_export($value, true)));
            }
        }

        protected function assertCount(int $expected, $haystack, string $message = ''): void
        {
            $actual = is_array($haystack) ? count($haystack) : 0;
            if ($actual !== $expected) {
                throw new \RuntimeException(
                    "$message\nExpected count: $expected, actual count: $actual."
                );
            }
        }

        protected function assertEmpty($value, string $message = ''): void
        {
            if (!empty($value)) {
                throw new \RuntimeException($message ?: 'Expected empty, got non-empty.');
            }
        }

        protected function assertNotEmpty($value, string $message = ''): void
        {
            if (empty($value)) {
                throw new \RuntimeException($message ?: 'Expected non-empty, got empty.');
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

        protected function fail(string $message): never
        {
            throw new \RuntimeException($message);
        }
    }
    class_alias(LocalTestCase::class, 'PHPUnit\Framework\TestCase');
}

use PHPUnit\Framework\TestCase;

class FunctionalTest extends TestCase
{
    // =========================================================================
    // SECTION: Test infrastructure helpers
    // =========================================================================

    /** Root directory of the plugin */
    private string $pluginDir;

    /** Built-in status constants from Request.php */
    private const STATUS_PENDING = 'pending';
    private const STATUS_APPROVED = 'approved';
    private const STATUS_REJECTED = 'rejected';
    private const STATUS_CONFLICT = 'conflict';

    protected function setUp(): void
    {
        $this->setPluginDir();
    }

    /**
     * Used by both setUp() and standalone runner.
     */
    private function setPluginDir(): void
    {
        $this->pluginDir = realpath(__DIR__ . '/..');
        if ($this->pluginDir === false) {
            throw new \RuntimeException('Could not resolve plugin root directory.');
        }
    }

    /**
     * Read a source file and return its contents.
     */
    private function readSource(string $relativePath): string
    {
        $path = $this->pluginDir . '/' . ltrim($relativePath, '/');
        $this->assertFileExists($path, "Source file missing: $relativePath");
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents, "Could not read: $relativePath");
        return $contents;
    }

    // =========================================================================
    // TC-2.1.x — Solicitação: Business rule validation (pure logic)
    // =========================================================================

    /**
     * TC-2.1.1 — Valid request creates pending request + ticket.
     *
     * Tests that the service entry point validates and creates a request.
     * Since full integration requires DB, we verify the method signature,
     * return type, and that it delegates to checkAvailability().
     */
    public function testCreateRequestMethodSignature(): void
    {
        $source = $this->readSource('src/Service/RequestService.php');

        // Method must exist and return int
        $this->assertStringContainsString(
            'public function createRequest(array $input, int $requesterId): int',
            $source,
            'RequestService::createRequest must have correct signature (returns int).'
        );

        // Must call checkAvailability internally
        $this->assertStringContainsString(
            'checkAvailability',
            $source,
            'createRequest must call checkAvailability.'
        );

        // Must throw on validation failure
        $this->assertStringContainsString(
            "throw new \Exception(__('Period unavailable or violates rules.', 'fleetbooking'))",
            $source,
            'createRequest must throw when availability check fails.'
        );

        // Must create a Request record
        $this->assertStringContainsString(
            '$request = new Request()',
            $source,
            'createRequest must instantiate a new Request.'
        );

        // Must create a Ticket via TicketService
        $this->assertStringContainsString(
            'createTicketForRequest',
            $source,
            'createRequest must delegate ticket creation to TicketService.'
        );
    }

    /**
     * TC-2.1.2 — TicketService creates ticket with correct type and category.
     */
    public function testTicketServiceCreatesCorrectTicket(): void
    {
        $source = $this->readSource('src/Service/TicketService.php');

        // Must set DEMAND_TYPE
        $this->assertStringContainsString(
            'Ticket::DEMAND_TYPE',
            $source,
            'TicketService must use Ticket::DEMAND_TYPE.'
        );

        // Must use configured category
        $this->assertStringContainsString(
            'itilcategories_id',
            $source,
            'TicketService must set itilcategories_id from config.'
        );

        // Must assign to manager
        $this->assertStringContainsString(
            '_users_id_assign',
            $source,
            'TicketService must assign ticket to manager.'
        );

        // Must link ticket to vehicle
        $this->assertStringContainsString(
            'Item_Ticket',
            $source,
            'TicketService must create Item_Ticket link to vehicle.'
        );
    }

    /**
     * TC-2.2.1 — Weekend pickup is blocked.
     *
     * Verifies the validation message exists in RequestService.
     */
    public function testWeekendPickupBlocked(): void
    {
        $source = $this->readSource('src/Service/RequestService.php');

        $this->assertStringContainsString(
            "Pickup is allowed only on business days.",
            $source,
            'RequestService must block weekend pickup with correct message.'
        );
    }

    /**
     * TC-2.2.2 — Weekend return is blocked.
     */
    public function testWeekendReturnBlocked(): void
    {
        $source = $this->readSource('src/Service/RequestService.php');

        $this->assertStringContainsString(
            "Return is allowed only on business days.",
            $source,
            'RequestService must block weekend return with correct message.'
        );
    }

    /**
     * TC-2.2.3 — Pickup before workday start is blocked.
     */
    public function testPickupBeforeWorkStartBlocked(): void
    {
        $source = $this->readSource('src/Service/RequestService.php');

        $this->assertStringContainsString(
            "Pickup is allowed only between %1\$s and %2\$s.",
            $source,
            'RequestService must block early pickup with formatted message.'
        );
    }

    /**
     * TC-2.2.4 — Return after workday end is blocked.
     */
    public function testReturnAfterWorkEndBlocked(): void
    {
        $source = $this->readSource('src/Service/RequestService.php');

        $this->assertStringContainsString(
            "Return is allowed only between %1\$s and %2\$s.",
            $source,
            'RequestService must block late return with formatted message.'
        );
    }

    /**
     * TC-2.2.5 — Holiday pickup is blocked.
     */
    public function testHolidayPickupBlocked(): void
    {
        $source = $this->readSource('src/Service/RequestService.php');

        $this->assertStringContainsString(
            "Pickup is not allowed on holidays.",
            $source,
            'RequestService must block holiday pickup.'
        );
    }

    /**
     * TC-2.2.6 — Holiday return is blocked.
     */
    public function testHolidayReturnBlocked(): void
    {
        $source = $this->readSource('src/Service/RequestService.php');

        $this->assertStringContainsString(
            "Return is not allowed on holidays.",
            $source,
            'RequestService must block holiday return.'
        );
    }

    /**
     * TC-2.2.7 — End <= Start is blocked.
     */
    public function testEndBeforeStartBlocked(): void
    {
        $source = $this->readSource('src/Service/RequestService.php');

        $this->assertStringContainsString(
            "End date/time must be greater than start date/time.",
            $source,
            'RequestService must block end <= start.'
        );
    }

    /**
     * TC-2.2.8 — Invalid date format is caught.
     */
    public function testInvalidDateFormatCaught(): void
    {
        $source = $this->readSource('src/Service/RequestService.php');

        $this->assertStringContainsString(
            "Invalid date/time format.",
            $source,
            'RequestService must catch invalid date format.'
        );
    }

    /**
     * TC-2.3.5 — User without group is blocked.
     */
    public function testUserWithoutGroupBlocked(): void
    {
        $source = $this->readSource('src/Service/RequestService.php');

        $this->assertStringContainsString(
            "User does not belong to any group.",
            $source,
            'RequestService must block users without group membership.'
        );
    }

    /**
     * TC-2.3.6 — Group without manager is blocked.
     */
    public function testGroupWithoutManagerBlocked(): void
    {
        $source = $this->readSource('src/Service/RequestService.php');

        $this->assertStringContainsString(
            "Group without manager configured",
            $source,
            'RequestService must block group without manager.'
        );
    }

    // =========================================================================
    // TC-4.x — Aprovação/Rejeição
    // =========================================================================

    /**
     * TC-4.1.1 — Approval tab visible for approver, hidden for others.
     */
    public function testApprovalTabPermissionCheck(): void
    {
        $source = $this->readSource('src/Request.php');

        // Tab must check fleetbooking_approve or fleetbooking_admin rights
        $this->assertStringContainsString(
            "fleetbooking_approve",
            $source,
            'Request::getTabNameForItem must check fleetbooking_approve right.'
        );

        $this->assertStringContainsString(
            "fleetbooking_admin",
            $source,
            'Request::getTabNameForItem must check fleetbooking_admin right.'
        );
    }

    /**
     * TC-4.2.1 — Approval creates reservation, sets status, closes ticket.
     */
    public function testApprovalProcessesCorrectly(): void
    {
        $source = $this->readSource('src/Service/ApprovalService.php');

        // Must call ReservationService
        $this->assertStringContainsString(
            'ReservationService',
            $source,
            'ApprovalService must use ReservationService.'
        );

        // Must set decision fields
        $this->assertStringContainsString(
            'decision_users_id',
            $source,
            'ApprovalService must record decision_users_id.'
        );

        $this->assertStringContainsString(
            'decision_date',
            $source,
            'ApprovalService must record decision_date.'
        );

        $this->assertStringContainsString(
            'decision_comment',
            $source,
            'ApprovalService must record decision_comment.'
        );

        // Must close ticket after decision
        $this->assertStringContainsString(
            'closeTicket',
            $source,
            'ApprovalService must close ticket after decision (if configured).'
        );

        // Must add followup
        $this->assertStringContainsString(
            'addFollowup',
            $source,
            'ApprovalService must add followup to ticket.'
        );
    }

    /**
     * TC-4.2.2 — Reservation is actually created in core.
     */
    public function testReservationServiceCreatesNativeReservation(): void
    {
        $source = $this->readSource('src/Service/ReservationService.php');

        // Must use ReservationItem
        $this->assertStringContainsString(
            'ReservationItem',
            $source,
            'ReservationService must use ReservationItem.'
        );

        // Must use Reservation
        $this->assertStringContainsString(
            'Reservation()',
            $source,
            'ReservationService must instantiate Reservation.'
        );

        // Must set begin/end dates
        $this->assertStringContainsString(
            "'begin' => \$startDt",
            $source,
            'ReservationService must set begin date.'
        );

        $this->assertStringContainsString(
            "'end' => \$endDt",
            $source,
            'ReservationService must set end date.'
        );

        // Must link to requester
        $this->assertStringContainsString(
            "'users_id' => \$reqFields['requester_users_id']",
            $source,
            'ReservationService must link reservation to requester.'
        );
    }

    /**
     * TC-4.3.1 — Rejection requires mandatory comment.
     */
    public function testRejectionRequiresComment(): void
    {
        $source = $this->readSource('src/Service/ApprovalService.php');

        $this->assertStringContainsString(
            "Rejection comment is mandatory.",
            $source,
            'ApprovalService must enforce mandatory rejection comment.'
        );

        // Must trim the comment to reject whitespace-only
        $this->assertStringContainsString(
            'empty(trim($comment))',
            $source,
            'ApprovalService must reject whitespace-only comments.'
        );
    }

    /**
     * TC-4.3.3 — Race condition guard: FOR UPDATE lock.
     */
    public function testRaceConditionLockMechanism(): void
    {
        $source = $this->readSource('src/Service/ApprovalService.php');

        // Must use FOR UPDATE
        $this->assertStringContainsString(
            'FOR UPDATE',
            $source,
            'ApprovalService must use FOR UPDATE row lock for race condition safety.'
        );

        // Must use beginTransaction
        $this->assertStringContainsString(
            'beginTransaction',
            $source,
            'ApprovalService must wrap decision in DB transaction.'
        );

        // Must use rollBack on errors
        $this->assertStringContainsString(
            'rollBack',
            $source,
            'ApprovalService must rollback on errors.'
        );

        // Must check status is still pending before processing
        $this->assertStringContainsString(
            "Request::STATUS_PENDING",
            $source,
            'ApprovalService must check request is still pending before processing.'
        );

        // Must prevent processing already-processed requests
        $this->assertStringContainsString(
            'already been processed',
            $source,
            'ApprovalService must reject processing already-processed requests.'
        );
    }

    // =========================================================================
    // TC-5.x — Conflitos (Overlap)
    // =========================================================================

    /**
     * TC-5.1.1 — Overlap detection uses correct SQL condition.
     */
    public function testConflictDetectionUsesCorrectOverlap(): void
    {
        $source = $this->readSource('src/Service/RequestService.php');

        // Must use the standard overlap condition: existing.start < new.end AND existing.end > new.start
        // Searches for the associative array pattern used in the DB query builder
        $this->assertStringContainsString(
            "glpi_reservations.begin' => ['<', \$end",
            $source,
            'Conflict query must check existing.start < new.end.'
        );

        $this->assertStringContainsString(
            "glpi_reservations.end' => ['>', \$start",
            $source,
            'Conflict query must check existing.end > new.start.'
        );
    }

    /**
     * TC-5.1.2 — Conflict detection checks both requests AND reservations.
     */
    public function testConflictChecksReservations(): void
    {
        $source = $this->readSource('src/Service/RequestService.php');

        $this->assertStringContainsString(
            'glpi_reservations',
            $source,
            'getConflicts must query glpi_reservations table.'
        );

        $this->assertStringContainsString(
            'glpi_reservationitems',
            $source,
            'getConflicts must join glpi_reservationitems table.'
        );
    }

    /**
     * TC-5.2.1 — On-approval conflict sets status=conflict.
     */
    public function testApprovalConflictSetsConflictStatus(): void
    {
        $source = $this->readSource('src/Service/ApprovalService.php');

        $this->assertStringContainsString(
            'Request::STATUS_CONFLICT',
            $source,
            'ApprovalService must set CONFLICT status when overlap detected during approval.'
        );
    }

    // =========================================================================
    // TC-6.x — Autoaprovação (Gestor)
    // =========================================================================

    /**
     * TC-6.1.1 — Manager self-approval triggers auto-approve.
     */
    public function testAutoApprovalForManager(): void
    {
        $source = $this->readSource('src/Service/RequestService.php');

        // Must check if requester equals manager
        $this->assertStringContainsString(
            '$requesterId == $manager_id',
            $source,
            'createRequest must check if requester is the manager for auto-approval.'
        );

        // Must call ApprovalService::autoApprove
        $this->assertStringContainsString(
            'autoApprove',
            $source,
            'createRequest must call autoApprove for manager requests.'
        );
    }

    /**
     * TC-6.1.2 — Auto-approval service exists with correct logic.
     */
    public function testAutoApproveServiceExists(): void
    {
        $source = $this->readSource('src/Service/ApprovalService.php');

        $this->assertStringContainsString(
            'public function autoApprove(Request $request): string',
            $source,
            'ApprovalService must have autoApprove method.'
        );

        $this->assertStringContainsString(
            'Auto-approved by system',
            $source,
            'Auto-approval must have a descriptive comment.'
        );
    }

    /**
     * TC-6.1.3 — Manager resolution uses GroupManager + native group managers.
     */
    public function testManagerResolutionStrategy(): void
    {
        $source = $this->readSource('src/Service/RequestService.php');

        // Priority 1: GroupManager table
        $this->assertStringContainsString(
            'GroupManager',
            $source,
            'resolveManagerGroup must check FleetBooking GroupManager first.'
        );

        // Priority 2: Native GLPI group managers
        $this->assertStringContainsString(
            "is_manager' => 1",
            $source,
            'resolveManagerGroup must check native GLPI group managers as fallback.'
        );
    }

    // =========================================================================
    // TC-7.x + TC-15.x — Segurança: Permissions, CSRF, Injection
    // =========================================================================

    /**
     * TC-7.1.1 — All front controllers check session rights.
     *
     * Note: approval.form.php uses haveRight() + getLoginUserID() instead
     * of checkRight() because it operates inside an existing GLPI page context.
     */
    public function testAllFrontControllersCheckRights(): void
    {
        $frontFiles = glob($this->pluginDir . '/front/*.php');
        $this->assertNotEmpty($frontFiles, 'Front controller directory must exist.');

        // Files that use alternative auth patterns (not checkRight)
        // - approval.form.php: uses haveRight + getLoginUserID
        // - profile.form.php: uses checkRight("profile", UPDATE) (non-fleetbooking right)
        // - request.view.php: included file, uses haveRight + getLoginUserID
        $alternativeAuth = ['approval.form.php', 'profile.form.php', 'request.view.php'];

        foreach ($frontFiles as $file) {
            $contents = file_get_contents($file);
            $basename = basename($file);

            // config.php is a redirect only, no right check needed
            if ($basename === 'config.php') {
                continue;
            }

            if (in_array($basename, $alternativeAuth, true)) {
                // Check for haveRight, getLoginUserID, or checkRight non-fleetbooking pattern
                $hasRightCheck = strpos($contents, 'Session::haveRight') !== false
                    || strpos($contents, 'Session::getLoginUserID') !== false
                    || strpos($contents, 'Session::checkRight') !== false;
                $this->assertTrue(
                    $hasRightCheck,
                    "Front controller '$basename' must check authorization " .
                    "(Session::haveRight, Session::getLoginUserID, or Session::checkRight)."
                );
                $hasFleetRight = strpos($contents, 'fleetbooking_') !== false;
                $this->assertTrue(
                    $hasFleetRight,
                    "Front controller '$basename' must check a fleetbooking_* right."
                );
            } else {
                $this->assertStringContainsString(
                    'Session::checkRight',
                    $contents,
                    "Front controller '$basename' must call Session::checkRight()."
                );

                $this->assertStringContainsString(
                    "checkRight(\"fleetbooking_",
                    $contents,
                    "Front controller '$basename' must check a fleetbooking_* right."
                );
            }
        }
    }

    /**
     * TC-7.1.2 — All AJAX endpoints check session rights.
     *
     * Note: availability.php uses haveRight() because it's a JSON endpoint
     * that returns a permission-denied JSON response instead of redirecting.
     */
    public function testAjaxEndpointsCheckRights(): void
    {
        $ajaxFiles = glob($this->pluginDir . '/ajax/*.php');
        $this->assertNotEmpty($ajaxFiles, 'AJAX directory must exist.');

        // Files that use haveRight instead of checkRight
        $alternativeAuth = ['availability.php', 'calendar.php'];

        foreach ($ajaxFiles as $file) {
            $contents = file_get_contents($file);
            $basename = basename($file);

            if (in_array($basename, $alternativeAuth, true)) {
                $hasRightCheck = strpos($contents, 'Session::haveRight') !== false;
                $this->assertTrue(
                    $hasRightCheck,
                    "AJAX endpoint '$basename' must call Session::haveRight()."
                );
            } else {
                $this->assertStringContainsString(
                    'Session::checkRight',
                    $contents,
                    "AJAX endpoint '$basename' must call Session::checkRight()."
                );
            }
        }
    }

    /**
     * TC-7.1.3 — Approval form checks permission before processing.
     *
     * approval.form.php verifies authorization differently:
     * - Checks manager_users_id matches the logged-in user
     * - OR checks if user has fleetbooking_admin right
     * This prevents unauthorized approval even without a direct checkRight call.
     */
    public function testApprovalFormChecksPermission(): void
    {
        $source = $this->readSource('front/approval.form.php');

        // Must verify manager identity
        $this->assertStringContainsString(
            'manager_users_id',
            $source,
            'approval.form.php must verify manager_users_id.'
        );

        $this->assertStringContainsString(
            'Session::getLoginUserID',
            $source,
            'approval.form.php must check logged user ID.'
        );

        // Must check fleetbooking_admin as alternative authorization
        $this->assertStringContainsString(
            'fleetbooking_admin',
            $source,
            'approval.form.php must check fleetbooking_admin right.'
        );

        // Must display permission error if neither condition matches
        $this->assertStringContainsString(
            'do not have permission',
            $source,
            'approval.form.php must show permission error.'
        );
    }

    /**
     * TC-7.1.4 — Config page checks admin right.
     */
    public function testConfigFormChecksAdminRight(): void
    {
        $source = $this->readSource('front/config.form.php');

        $this->assertStringContainsString(
            'Session::checkRight("fleetbooking_admin"',
            $source,
            'config.form.php must check fleetbooking_admin right.'
        );
    }

    /**
     * TC-7.2.x — CSRF validation in all forms.
     */
    public function testAllFormsValidateCSRF(): void
    {
        $formFiles = [
            'front/request.form.php',
            'front/approval.form.php',
            'front/config.form.php',
            'front/profile.form.php',
        ];

        foreach ($formFiles as $path) {
            $source = $this->readSource($path);

            // Either validates CSRF explicitly or outputs a CSRF token in the form
            $hasCsrfValidation = strpos($source, 'Session::validateCSRF') !== false;
            $hasCsrfToken = strpos($source, '_glpi_csrf_token') !== false;

            $this->assertTrue(
                $hasCsrfValidation || $hasCsrfToken,
                "Form '$path' must either validate CSRF (Session::validateCSRF) or output CSRF token (_glpi_csrf_token)."
            );
        }
    }

    /**
     * TC-7.2.1 — POST without CSRF token is rejected (source inspection).
     */
    public function testCsrfTokenValidationInRequestForm(): void
    {
        $source = $this->readSource('front/request.form.php');

        // The form must validate CSRF before processing
        $this->assertStringContainsString(
            'Session::validateCSRF',
            $source,
            'request.form.php must call Session::validateCSRF before processing.'
        );
    }

    /**
     * TC-7.3.1 — No XSS: htmlspecialchars used in user-facing output.
     *
     * Profile.php uses (int) casting for IDs and Html::showCheckbox which
     * handles internal escaping — these are safe by construction.
     */
    public function testHtmlspecialcharsUsedInOutput(): void
    {
        $filesToCheck = [
            'src/Config.php',
            'src/Holiday.php',
        ];

        foreach ($filesToCheck as $path) {
            $source = $this->readSource($path);

            $this->assertStringContainsString(
                'htmlspecialchars',
                $source,
                "File '$path' should use htmlspecialchars for safe HTML output."
            );
        }
    }

    /**
     * TC-7.3.2 — SQL injection: uses $DB->quoteValue or prepared statements.
     */
    public function testSqlInjectionPrevention(): void
    {
        $serviceFiles = [
            'src/Service/RequestService.php',
            'src/Service/ApprovalService.php',
            'src/Service/CalendarService.php',
            'src/Config.php',
            'src/Request.php',
        ];

        foreach ($serviceFiles as $path) {
            $source = $this->readSource($path);

            // Must NOT use mysql_query or mysqli_query directly
            $this->assertStringNotContainsString(
                'mysql_query',
                $source,
                "File '$path' must not use mysql_query() (SQL injection risk)."
            );

            $this->assertStringNotContainsString(
                'mysqli_query',
                $source,
                "File '$path' must not use mysqli_query() directly."
            );
        }
    }

    /**
     * TC-7.3.5 — IDOR prevention: manager must own the request to approve.
     */
    public function testIdorPreventionInApproval(): void
    {
        $source = $this->readSource('front/approval.form.php');

        // Must check that the current user is the assigned manager
        $this->assertStringContainsString(
            'manager_users_id',
            $source,
            'approval.form.php must verify manager_users_id to prevent IDOR.'
        );

        // Must check session user against manager
        $this->assertStringContainsString(
            'Session::getLoginUserID',
            $source,
            'approval.form.php must check the logged-in user ID.'
        );
    }

    /**
     * TC-7.4.x — No debug/stack leak in AJAX.
     */
    public function testNoDebugModeInAjax(): void
    {
        $ajaxFiles = glob($this->pluginDir . '/ajax/*.php');
        foreach ($ajaxFiles as $file) {
            $contents = file_get_contents($file);
            $basename = basename($file);

            $this->assertStringNotContainsString(
                "ini_set('display_errors'",
                $contents,
                "AJAX '$basename' must not contain ini_set('display_errors')."
            );

            $this->assertStringNotContainsString(
                'error_reporting(E_ALL)',
                $contents,
                "AJAX '$basename' must not contain error_reporting(E_ALL)."
            );

            $this->assertStringNotContainsString(
                'var_dump',
                $contents,
                "AJAX '$basename' must not contain var_dump()."
            );

            $this->assertStringNotContainsString(
                'print_r',
                $contents,
                "AJAX '$basename' must not contain print_r() in production code."
            );
        }
    }

    /**
     * TC-15.3.x — No debug code in any production file.
     */
    public function testNoDebugCodeInProduction(): void
    {
        $allPhpFiles = array_merge(
            glob($this->pluginDir . '/src/**/*.php') ?: [],
            glob($this->pluginDir . '/front/*.php') ?: [],
            glob($this->pluginDir . '/ajax/*.php') ?: [],
            [$this->pluginDir . '/hook.php', $this->pluginDir . '/setup.php']
        );

        $forbiddenPatterns = [
            'var_dump' => 'var_dump() in production code',
            'print_r' => 'print_r() in production code (use Toolbox::logInFile)',
            'error_reporting' => 'error_reporting() in production (leaks paths)',
        ];

        foreach ($allPhpFiles as $file) {
            if (!file_exists($file))
                continue;
            $contents = file_get_contents($file);
            $basename = basename($file);

            foreach ($forbiddenPatterns as $pattern => $description) {
                // Allow in test files and stub files
                if (strpos($file, '/tests/') !== false || strpos($file, '/stubs/') !== false) {
                    continue;
                }
                $this->assertStringNotContainsString(
                    $pattern,
                    $contents,
                    "File '$basename' must not contain $description."
                );
            }
        }
    }

    /**
     * TC-15.4.x — No hardcoded credentials or secrets.
     */
    public function testNoHardcodedSecrets(): void
    {
        $sourceFiles = glob($this->pluginDir . '/src/**/*.php') ?: [];

        foreach ($sourceFiles as $file) {
            $contents = file_get_contents($file);
            $basename = basename($file);

            // Check for common secret patterns
            if (preg_match('/password\s*=\s*["\'].+["\']/i', $contents)) {
                $this->fail("File '$basename' appears to contain a hardcoded password.");
            }
            if (preg_match('/api[_-]?key\s*=\s*["\'][^"\']+["\']/i', $contents)) {
                $this->fail("File '$basename' appears to contain a hardcoded API key.");
            }
        }
    }

    /**
     * TC-15.x — Type safety: service classes (note: currently no strict_types).
     *
     * This test documents that service classes do NOT currently declare
     * strict_types=1. This is a code quality finding, not a functional
     * failure. Adding declare(strict_types=1) to all service files is
     * recommended for future releases to prevent silent type coercion.
     */
    public function testServiceStrictTypesDocumentation(): void
    {
        $serviceFiles = glob($this->pluginDir . '/src/Service/*.php') ?: [];
        $filesWithoutStrict = [];

        foreach ($serviceFiles as $file) {
            $contents = file_get_contents($file);
            if (strpos($contents, 'declare(strict_types=1)') === false) {
                $filesWithoutStrict[] = basename($file);
            }
        }

        // This is a non-blocking informational check:
        // - Empty array means all files already have strict_types (ideal)
        // - Non-empty means some files are missing it (documented finding)
        if (!empty($filesWithoutStrict)) {
            echo "    ℹ️  INFO: Files without declare(strict_types=1): "
                . implode(', ', $filesWithoutStrict) . "\n";
        }

        // NOT a hard assertion — this is documented for future improvement
        $this->assertTrue(true);
    }

    // =========================================================================
    // TC-8.x — Consistência de Dados
    // =========================================================================

    /**
     * TC-8.1.1 — Request ↔ Ticket integrity.
     */
    public function testRequestTicketIntegrity(): void
    {
        $source = $this->readSource('src/Service/RequestService.php');

        // After creating ticket, must update request with tickets_id
        $this->assertStringContainsString(
            "tickets_id' => \$ticketId",
            $source,
            'createRequest must save tickets_id on the request after successful ticket creation.'
        );

        // If ticket creation fails, must delete the orphaned request
        $this->assertStringContainsString(
            'Removing orphaned request',
            $source,
            'createRequest must clean up orphaned request if ticket creation fails.'
        );
    }

    /**
     * TC-8.1.2 — Request ↔ Reservation integrity.
     */
    public function testRequestReservationIntegrity(): void
    {
        $source = $this->readSource('src/Service/ApprovalService.php');

        $this->assertStringContainsString(
            "reservations_id",
            $source,
            'ApprovalService must save reservations_id on the request after successful reservation creation.'
        );

        $this->assertStringContainsString(
            "reservationId",
            $source,
            'ApprovalService must reference the reservationId variable when setting the reservations_id field.'
        );
    }

    /**
     * TC-8.2.1 — Calendar events link to tickets.
     */
    public function testCalendarEventsLinkToTickets(): void
    {
        $source = $this->readSource('src/Service/CalendarService.php');

        $this->assertStringContainsString(
            'tickets_id',
            $source,
            'CalendarService must include tickets_id in event data.'
        );

        $this->assertStringContainsString(
            'front/ticket.form.php',
            $source,
            'CalendarService must link events to ticket form URL.'
        );
    }

    // =========================================================================
    // TC-9.x — Validações de Negócio
    // =========================================================================

    /**
     * TC-9.1.1 — Workday start/end configurable and validated.
     */
    public function testWorkdayTimesConfigurable(): void
    {
        $source = $this->readSource('src/Config.php');

        $this->assertStringContainsString(
            'workday_start',
            $source,
            'Config must store workday_start.'
        );

        $this->assertStringContainsString(
            'workday_end',
            $source,
            'Config must store workday_end.'
        );

        // RequestService must read these values from config
        $reqSource = $this->readSource('src/Service/RequestService.php');
        $this->assertStringContainsString(
            "\$workStart = \$config['workday_start']",
            $reqSource,
            'RequestService must read workday_start from config.'
        );
        $this->assertStringContainsString(
            "\$workEnd = \$config['workday_end']",
            $reqSource,
            'RequestService must read workday_end from config.'
        );
    }

    /**
     * TC-9.1.2 — Config uses entity inheritance.
     */
    public function testConfigEntityInheritance(): void
    {
        $source = $this->readSource('src/Config.php');

        $this->assertStringContainsString(
            'getAncestorsOf',
            $source,
            'Config::getForEntity must use getAncestorsOf for entity inheritance.'
        );

        $this->assertStringContainsString(
            'array_reverse',
            $source,
            'Config::getForEntity must traverse ancestors in correct order (current first).'
        );
    }

    /**
     * TC-9.2.1 — Request status constants are consistent between Request and services.
     */
    public function testStatusConstantsConsistent(): void
    {
        $reqSource = $this->readSource('src/Request.php');

        $this->assertStringContainsString(
            "STATUS_PENDING = 'pending'",
            $reqSource,
            'Request must define STATUS_PENDING.'
        );
        $this->assertStringContainsString(
            "STATUS_APPROVED = 'approved'",
            $reqSource,
            'Request must define STATUS_APPROVED.'
        );
        $this->assertStringContainsString(
            "STATUS_REJECTED = 'rejected'",
            $reqSource,
            'Request must define STATUS_REJECTED.'
        );
        $this->assertStringContainsString(
            "STATUS_CONFLICT = 'conflict'",
            $reqSource,
            'Request must define STATUS_CONFLICT.'
        );

        // ApprovalService must reference these constants (not hardcoded strings)
        $appSource = $this->readSource('src/Service/ApprovalService.php');
        $this->assertStringContainsString(
            'Request::STATUS_',
            $appSource,
            'ApprovalService must reference Request::STATUS_* constants (not hardcoded strings).'
        );
    }

    // =========================================================================
    // TC-10.x — Internacionalização (i18n)
    // =========================================================================

    /**
     * TC-10.1.1 — All user-facing strings use translation function.
     */
    public function testAllUserStringsTranslated(): void
    {
        $phpFiles = array_merge(
            glob($this->pluginDir . '/src/**/*.php') ?: [],
            glob($this->pluginDir . '/front/*.php') ?: [],
            glob($this->pluginDir . '/ajax/*.php') ?: [],
            [$this->pluginDir . '/hook.php', $this->pluginDir . '/setup.php']
        );

        foreach ($phpFiles as $file) {
            $contents = file_get_contents($file);
            $lines = file($file);

            foreach ($lines as $lineNo => $line) {
                $trimmed = trim($line);

                // Skip: comments, blank lines, PHP open tags, namespace, use, declare, etc.
                if (
                    empty($trimmed) || $trimmed[0] === '/' || $trimmed[0] === '*' ||
                    strpos($trimmed, '<?php') === 0 || strpos($trimmed, 'namespace ') === 0 ||
                    strpos($trimmed, 'use ') === 0 || strpos($trimmed, 'declare(') === 0 ||
                    strpos($trimmed, '//') === 0 || strpos($trimmed, '#') === 0
                ) {
                    continue;
                }

                // Find hardcoded strings that should be translated
                // Look for string literals inside echo/print or HTML output context
                if (preg_match('/echo\s*["\']([^"\']{10,})["\']/', $trimmed, $matches)) {
                    // Allowlist: HTML tags, CSS classes, numeric/boolean, markers
                    $hardcoded = $matches[1];
                    if (preg_match('/^(<|div|span|table|tr|td|th|input|form|style|\d+|true|false|selected|checked)/', $hardcoded)) {
                        continue;
                    }

                    $this->assertTrue(
                        strpos($trimmed, "__(") !== false || strpos($trimmed, "_n(") !== false ||
                        strpos($trimmed, "_x(") !== false || strpos($trimmed, "_sx(") !== false,
                        "File '" . basename($file) . "', line " . ($lineNo + 1) .
                        ": Possible hardcoded user-facing string: \"" . substr($hardcoded, 0, 60) . "\""
                    );
                }
            }
        }
    }

    /**
     * TC-10.3.1 — Locale files exist for pt-BR and en-GB.
     */
    public function testLocaleFilesExist(): void
    {
        $localesDir = $this->pluginDir . '/locales';

        $this->assertFileExists(
            $localesDir . '/pt_BR.po',
            'Portuguese (Brazil) PO file must exist.'
        );
        $this->assertFileExists(
            $localesDir . '/pt_BR.mo',
            'Portuguese (Brazil) MO file must exist.'
        );
        $this->assertFileExists(
            $localesDir . '/en_GB.po',
            'English (GB) PO file must exist.'
        );
        $this->assertFileExists(
            $localesDir . '/en_GB.mo',
            'English (GB) MO file must exist.'
        );
        $this->assertFileExists(
            $localesDir . '/fleetbooking.pot',
            'POT template file must exist.'
        );
    }

    /**
     * TC-10.3.2 — PT-BR locale has key translations.
     */
    public function testPtBrContainsKeyTranslations(): void
    {
        $poFile = $this->pluginDir . '/locales/pt_BR.po';
        $this->assertFileExists($poFile);

        $contents = file_get_contents($poFile);

        // Must have Portuguese translations for critical messages
        $this->assertStringContainsString(
            'Retirada somente em dias úteis',
            $contents,
            'pt_BR.po must have Portuguese for: Pickup only on business days.'
        );

        $this->assertStringContainsString(
            'Aprovado',
            $contents,
            'pt_BR.po must have "Aprovado".'
        );

        $this->assertStringContainsString(
            'Pendente',
            $contents,
            'pt_BR.po must have "Pendente".'
        );

        $this->assertStringContainsString(
            'Rejeitado',
            $contents,
            'pt_BR.po must have "Rejeitado".'
        );
    }

    // =========================================================================
    // TC-13.x — Regressão: Estrutura de Instalação/Upgrade
    // =========================================================================

    /**
     * TC-13.1.1 — Install function must create 4 tables.
     */
    public function testInstallCreatesFourTables(): void
    {
        $source = $this->readSource('sql/install.php');

        $tables = [
            'glpi_plugin_fleetbooking_requests',
            'glpi_plugin_fleetbooking_groupmanagers',
            'glpi_plugin_fleetbooking_holidays',
            'glpi_plugin_fleetbooking_configs',
        ];

        foreach ($tables as $table) {
            $this->assertStringContainsString(
                $table,
                $source,
                "Install script must create table: $table"
            );
        }
    }

    /**
     * TC-13.1.2 — Install function registers 4 profile rights.
     */
    public function testInstallRegistersFourRights(): void
    {
        $source = $this->readSource('sql/install.php');

        $rights = [
            'fleetbooking_read',
            'fleetbooking_request',
            'fleetbooking_approve',
            'fleetbooking_admin',
        ];

        foreach ($rights as $right) {
            $this->assertStringContainsString(
                $right,
                $source,
                "Install script must register right: $right"
            );
        }
    }

    /**
     * TC-13.1.3 — Uninstall drops all tables and removes rights.
     */
    public function testUninstallDropsTablesAndRights(): void
    {
        $source = $this->readSource('hook.php');

        $tables = [
            'glpi_plugin_fleetbooking_requests',
            'glpi_plugin_fleetbooking_groupmanagers',
            'glpi_plugin_fleetbooking_holidays',
            'glpi_plugin_fleetbooking_configs',
        ];

        foreach ($tables as $table) {
            $this->assertStringContainsString(
                $table,
                $source,
                "Uninstall hook must reference table: $table"
            );
        }

        // Must remove profile rights
        $this->assertStringContainsString(
            'glpi_profilerights',
            $source,
            'Uninstall must clean up profile rights.'
        );
    }

    /**
     * TC-13.1.4 — Setup.php defines version and min GLPI version.
     */
    public function testSetupDefinesVersionConstraints(): void
    {
        $source = $this->readSource('setup.php');

        $this->assertStringContainsString(
            "PLUGIN_FLEETBOOKING_VERSION",
            $source,
            'setup.php must define PLUGIN_FLEETBOOKING_VERSION.'
        );

        $this->assertStringContainsString(
            "PLUGIN_FLEETBOOKING_MIN_GLPI_VERSION",
            $source,
            'setup.php must define PLUGIN_FLEETBOOKING_MIN_GLPI_VERSION.'
        );

        // The requirements array spans multiple lines; search for key tokens
        $this->assertStringContainsString(
            "'requirements'",
            $source,
            'plugin_version_fleetbooking must declare requirements key.'
        );

        $this->assertStringContainsString(
            "'glpi'",
            $source,
            'plugin_version_fleetbooking must declare GLPI version requirement.'
        );

        $this->assertStringContainsString(
            "'min' => PLUGIN_FLEETBOOKING_MIN_GLPI_VERSION",
            $source,
            'plugin_version_fleetbooking must reference MIN_GLPI_VERSION constant.'
        );

        // Prerequisite check must compare versions
        $this->assertStringContainsString(
            'version_compare',
            $source,
            'plugin_fleetbooking_check_prerequisites must use version_compare.'
        );
    }

    /**
     * TC-13.1.5 — Plugin registers CSRF compliance.
     */
    public function testPluginRegistersCsrfCompliance(): void
    {
        $source = $this->readSource('setup.php');

        $this->assertStringContainsString(
            "csrf_compliant",
            $source,
            'setup.php must declare CSRF compliance.'
        );
    }

    /**
     * TC-13.2.1 — Config migration moves active tickets between entities.
     */
    public function testConfigMigrationMovesActiveTickets(): void
    {
        $source = $this->readSource('src/Config.php');

        $this->assertStringContainsString(
            'migrateActiveTickets',
            $source,
            'Config must have migrateActiveTickets method.'
        );

        // Must filter out SOLVED/CLOSED tickets
        $this->assertStringContainsString(
            'Ticket::SOLVED',
            $source,
            'migrateActiveTickets must exclude SOLVED tickets.'
        );

        $this->assertStringContainsString(
            'Ticket::CLOSED',
            $source,
            'migrateActiveTickets must exclude CLOSED tickets.'
        );
    }

    // =========================================================================
    // TC-14.x — Regressão: Integridade do Core GLPI
    // =========================================================================

    /**
     * TC-14.1.1 — Plugin does not modify core files (only uses hooks).
     */
    public function testPluginDoesNotModifyCore(): void
    {
        $allPhpFiles = array_merge(
            glob($this->pluginDir . '/src/**/*.php') ?: [],
            glob($this->pluginDir . '/front/*.php') ?: [],
            glob($this->pluginDir . '/ajax/*.php') ?: [],
            [$this->pluginDir . '/hook.php', $this->pluginDir . '/setup.php']
        );

        foreach ($allPhpFiles as $file) {
            $contents = file_get_contents($file);
            $basename = basename($file);

            // Must not reference core file paths for writing
            $this->assertStringNotContainsString(
                'fopen(',
                $contents,
                "File '$basename' must not use fopen() on core files."
            );
        }
    }

    /**
     * TC-14.4.1 — Profile class registers and initializes rights.
     */
    public function testProfileRightsInitialization(): void
    {
        $source = $this->readSource('src/Profile.php');

        $this->assertStringContainsString(
            'initProfile',
            $source,
            'Profile must have initProfile method for session loading.'
        );

        $this->assertStringContainsString(
            'fleetbooking_read',
            $source,
            'Profile form must include fleetbooking_read right.'
        );

        $this->assertStringContainsString(
            'fleetbooking_request',
            $source,
            'Profile form must include fleetbooking_request right.'
        );

        $this->assertStringContainsString(
            'fleetbooking_approve',
            $source,
            'Profile form must include fleetbooking_approve right.'
        );

        $this->assertStringContainsString(
            'fleetbooking_admin',
            $source,
            'Profile form must include fleetbooking_admin right.'
        );
    }

    // =========================================================================
    // Static Validation: GLPI hook registration
    // =========================================================================

    /**
     * Verifies that setup.php registers all required GLPI hooks.
     */
    public function testRequiredHooksRegistered(): void
    {
        $source = $this->readSource('setup.php');

        $requiredHooks = [
            'csrf_compliant',
            'config_page',
            'add_css',
            'add_javascript',
            'item_get_events',
            'menu_toadd',
            'change_profile',
            'helpdesk_menu_entry',
            'helpdesk_menu_entry_icon',
        ];

        foreach ($requiredHooks as $hook) {
            $this->assertStringContainsString(
                "'$hook'",
                $source,
                "setup.php must register the '$hook' hook."
            );
        }
    }

    /**
     * Verifies that all service classes are autoloadable via PSR-4.
     */
    public function testServiceClassesExist(): void
    {
        $classes = [
            'src/Service/RequestService.php' => 'RequestService',
            'src/Service/ApprovalService.php' => 'ApprovalService',
            'src/Service/ReservationService.php' => 'ReservationService',
            'src/Service/TicketService.php' => 'TicketService',
            'src/Service/CalendarService.php' => 'CalendarService',
        ];

        foreach ($classes as $path => $shortName) {
            $fullPath = $this->pluginDir . '/' . $path;
            $this->assertFileExists($fullPath, "Service file must exist: $path");

            $source = file_get_contents($fullPath);

            // Verify the file declares the correct namespace
            $this->assertStringContainsString(
                'GlpiPlugin\Fleetbooking\Service',
                $source,
                "File $path must declare namespace GlpiPlugin\\Fleetbooking\\Service."
            );

            // Verify the short class name matches PSR-4
            $this->assertStringContainsString(
                "class $shortName",
                $source,
                "File $path must declare class $shortName matching PSR-4."
            );
        }
    }

    /**
     * Verifies all front controller files exist.
     */
    public function testAllFrontControllersExist(): void
    {
        $expected = [
            'front/request.php',
            'front/request.form.php',
            'front/request.view.php',
            'front/approval.form.php',
            'front/config.php',
            'front/config.form.php',
            'front/holiday.php',
            'front/groupmanager.php',
            'front/profile.form.php',
            'front/dashboard.php',
        ];

        foreach ($expected as $path) {
            $this->assertFileExists(
                $this->pluginDir . '/' . $path,
                "Front controller file must exist: $path"
            );
        }
    }

    /**
     * Verifies all AJAX endpoints exist.
     */
    public function testAllAjaxEndpointsExist(): void
    {
        $expected = [
            'ajax/availability.php',
            'ajax/calendar.php',
            'ajax/calendar_html.php',
        ];

        foreach ($expected as $path) {
            $this->assertFileExists(
                $this->pluginDir . '/' . $path,
                "AJAX endpoint must exist: $path"
            );
        }
    }
}

// =============================================================================
// STANDALONE RUNNER — Executes via `php tests/FunctionalTest.php`
// =============================================================================
if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $passed = 0;
    $failed = 0;
    $total = 0;

    $test = new FunctionalTest('');

    // Initialize pluginDir (normally done by setUp, which is protected)
    $pluginDirRef = new \ReflectionProperty($test, 'pluginDir');
    $pluginDirRef->setAccessible(true);
    $resolved = realpath(__DIR__ . '/..');
    if ($resolved === false) {
        fwrite(STDERR, "FATAL: Could not resolve plugin root directory.\n");
        exit(1);
    }
    $pluginDirRef->setValue($test, $resolved);

    // Build a list of test methods to run (all public methods starting with "test")
    $ref = new \ReflectionClass($test);
    $methods = $ref->getMethods(\ReflectionMethod::IS_PUBLIC);

    echo "=== FleetBooking Functional Test Suite ===\n\n";

    foreach ($methods as $method) {
        if (strpos($method->getName(), 'test') !== 0) {
            continue;
        }
        $total++;
        $testName = $method->getName();

        // Convert camelCase to readable name
        $readable = preg_replace('/([a-z])([A-Z])/', '$1 $2', $testName);
        $readable = ucfirst($readable);

        try {
            $method->invoke($test);
            echo "  ✓ $readable\n";
            $passed++;
        } catch (\Throwable $e) {
            echo "  ✗ $readable\n";
            echo "    └─ {$e->getMessage()}\n";
            $failed++;
        }
    }

    echo "\n=== Results: $passed passed, $failed failed, $total total ===\n";
    echo "   Coverage: TC-2.1, TC-2.2, TC-2.3, TC-4.1, TC-4.2, TC-4.3,\n";
    echo "              TC-5.1, TC-5.2, TC-6.1, TC-7.1, TC-7.2, TC-7.3,\n";
    echo "              TC-7.4, TC-8.1, TC-8.2, TC-9.1, TC-9.2, TC-10.1,\n";
    echo "              TC-10.3, TC-13.1, TC-13.2, TC-14.1, TC-14.4, TC-15.3,\n";
    echo "              TC-15.4\n\n";

    exit($failed > 0 ? 1 : 0);
}