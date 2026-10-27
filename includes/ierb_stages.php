<?php
/** V14 identities are independent of legacy display labels and numeric stage values. */
final class IerbStage
{
    public const INITIAL = 'initial_submission';
    public const CORRECTION = 'resubmission_amendment';
    public const GATHERING = 'approval_data_gathering';
    public const COMPLETED = 'ierb_completed';
    public const LABELS = [
        self::INITIAL => 'Initial Submission',
        self::CORRECTION => 'Resubmission / Amendment',
        self::GATHERING => 'Approval of Data Gathering',
        self::COMPLETED => 'IERB Completed',
    ];
    public const MAIN_PATH = [self::INITIAL, self::GATHERING, self::COMPLETED];
    public const TRANSITIONS = [
        'initial_requirements_accepted' => [self::INITIAL => self::GATHERING],
        'open_correction' => [self::INITIAL => self::CORRECTION, self::GATHERING => self::CORRECTION],
        'resolve_correction' => [self::CORRECTION => [self::INITIAL, self::GATHERING]],
        'final_report_submitted' => [self::GATHERING => self::COMPLETED],
    ];

    public static function requireKey(string $stage): void
    {
        if (!isset(self::LABELS[$stage])) throw new InvalidArgumentException('Unknown V14 stage identifier. Legacy values require an explicit evidence-based enrollment.');
    }

    public static function transition(string $from, string $event, ?string $interrupted = null): string
    {
        self::requireKey($from);
        $to = self::TRANSITIONS[$event][$from] ?? null;
        if ($event === 'resolve_correction' && is_array($to)) {
            if (!in_array($interrupted, $to, true)) throw new InvalidArgumentException('A correction must resume its recorded interrupted review stage.');
            return $interrupted;
        }
        if (!is_string($to)) throw new InvalidArgumentException('This event is not allowed from the current V14 stage.');
        return $to;
    }

    /** Percentage of the two normal forward transitions completed; correction adds no milestone. */
    public static function progress(string $stage, ?string $interrupted = null): int
    {
        self::requireKey($stage);
        if ($stage === self::CORRECTION) $stage = self::transition($stage, 'resolve_correction', $interrupted);
        return [self::INITIAL => 0, self::GATHERING => 50, self::COMPLETED => 100][$stage];
    }

    public static function contract(): array
    {
        return ['version' => 'ierb-v14', 'stages' => self::LABELS, 'normalPath' => self::MAIN_PATH,
            'transitions' => self::TRANSITIONS, 'progress' => [self::INITIAL => 0, self::GATHERING => 50, self::COMPLETED => 100],
            'correctionProgress' => 'Preserve the interrupted stage progress.',
            'completionEvent' => 'final_report_submitted', 'completionRequirement' => 'Form 33-0B — Final Report Form',
            'completionContract' => ['formalSubmissionRequired' => true, 'atomicWithSubmission' => true,
                'authorized' => true, 'idempotent' => true, 'auditable' => true,
                'additionalRpmsAcceptance' => false, 'manualCompletion' => false],
            'httpActivationAvailable' => false, 'documentIntegrationAvailable' => false];
    }
}
