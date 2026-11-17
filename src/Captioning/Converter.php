<?php

namespace Captioning;

use Captioning\Format\SubripFile;
use Captioning\Format\SubripCue;
use Captioning\Format\TtmlFile;
use Captioning\Format\WebvttFile;
use Captioning\Format\WebvttCue;
use Captioning\Format\SubstationalphaFile;
use Captioning\Format\SubstationalphaCue;

class Converter
{
    /**
     * Fields lost during the last conversion.
     * Each entry: ['cue' => int|null, 'field' => string, 'value' => mixed, 'reason' => string]
     *
     * @var array
     */
    private static $conversionLosses = [];

    /**
     * Returns the list of fields that could not be represented in the
     * target format during the last conversion.
     */
    public static function getConversionLosses(): array
    {
        return self::$conversionLosses;
    }

    /**
     * Clears the conversion loss report. Called automatically by
     * File::convertTo() before each conversion.
     */
    public static function resetConversionLosses(): void
    {
        self::$conversionLosses = [];
    }

    private static function recordLoss($cueIndex, string $field, $value, string $reason): void
    {
        self::$conversionLosses[] = [
            'cue'    => $cueIndex,
            'field'  => $field,
            'value'  => $value,
            'reason' => $reason,
        ];
    }

    /* fallback converter in case specific converter isn't implemented */
    public static function defaultConverter(FileInterface $_file, string $_convertTo): FileInterface
    {
        $subtitleClass = __NAMESPACE__.'\\Format\\'.ucfirst($_convertTo).'File';

        if (!class_exists($subtitleClass)) {
            throw new \InvalidArgumentException(sprintf('Unable to convert to "%s", this format does not exists.', $_convertTo));
        }

        $newSub = new $subtitleClass();
        $cueClass = File::getExpectedCueClass($newSub);

        foreach ($_file->getCues() as $i => $cue) {
            if ($cue instanceof WebvttCue) {
                self::recordWebvttCueLosses($cue, $i, $_convertTo, []);
            }
            $newSub->addCue($cue->getText(), $cueClass::ms2tc($cue->getStartMS()), $cueClass::ms2tc($cue->getStopMS()));
        }

        return $newSub;
    }

    /* subrip converters */
    public static function subrip2webvtt(SubripFile $_srt): WebvttFile
    {
        $vtt = new WebvttFile();
        foreach ($_srt->getCues() as $cue) {
            $vttCue = new WebvttCue(
                SubripCue::ms2tc($cue->getStartMS(), '.'),
                SubripCue::ms2tc($cue->getStopMS(), '.')
            );

            // recover positioning info from a leading ASS alignment tag ({\anN}),
            // then strip remaining SSA override tags like the previous behavior
            $text = self::subripAlignmentToWebvttSettings($cue->getText(), $vttCue);
            $text = preg_replace('/{[^}]+}/', '', $text);

            if (trim($text) !== '') {
                $vttCue->setText($text);
            }
            $vtt->addCue($vttCue);
        }

        return $vtt;
    }

    public static function subrip2substationalpha(SubripFile $_srt): SubstationalphaFile
    {
        $ass = new SubstationalphaFile();
        foreach ($_srt->getCues() as $cue) {
            $search  = ["\r\n", "\r", "\n", '<i>', '</i>', '<b>', '</b>', '<u>', '</u>'];
            $replace = ['\N', '\N', '\N', '{\i1}', '{\i0}', '{\b1}', '{\b0}', '{\u1}', '{\u0}'];
            $text    = str_replace($search, $replace, $cue->getText());

            $search_regex = [
                '#<font color="?\#?([a-fA-F0-9]{2})([a-fA-F0-9]{2})([a-fA-F0-9]{2})"?>(.+)</font>#is'
            ];
            $replace_regex = [
                '{\c&H$3$2$1&}$4'
            ];
            $text = preg_replace($search_regex, $replace_regex, $text);

            $ass->addCue($text, SubstationalphaCue::ms2tc($cue->getStartMS()), SubstationalphaCue::ms2tc($cue->getStopMS()));
        }

        return $ass;
    }

    /* webvtt converters */
    public static function webvtt2subrip(WebvttFile $_vtt): SubripFile
    {
        $srt = new SubripFile();

        if (count($_vtt->getRegions()) > 0) {
            self::recordLoss(null, 'regions', count($_vtt->getRegions()), 'subrip does not support regions');
        }

        foreach ($_vtt->getCues() as $i => $cue) {
            $text = $cue->getText();

            // map cue settings to a positioning hint SRT players understand
            $alignmentTag = self::webvttSettingsToSubripAlignment($cue);
            if ($alignmentTag !== null) {
                $text = $alignmentTag.$text;
            }

            self::recordWebvttCueLosses($cue, $i, 'subrip', ['align', 'position', 'line']);

            $srt->addCue($text, SubripCue::ms2tc($cue->getStartMS()), SubripCue::ms2tc($cue->getStopMS()));
        }

        return $srt;
    }

    public static function webvtt2substationalpha(WebvttFile $_vtt): SubstationalphaFile
    {
        return self::subrip2substationalpha(self::webvtt2subrip($_vtt));
    }

    /* substation alpha converters */
    public static function substationalpha2subrip(SubstationalphaFile $_ass): SubripFile
    {
        $srt = new SubripFile();
        foreach ($_ass->getCues() as $cue) {
            $search  = ['\N', '\N', '\N', '{\i1}', '{\i0}', '{\b1}', '{\b0}', '{\u1}', '{\u0}'];
            $replace = ["\r\n", "\r", "\n", '<i>', '</i>', '<b>', '</b>', '<u>', '</u>'];
            $text    = str_replace($search, $replace, $cue->getText());

            $search_regex = [
                '#{\\c&H([a-fA-F0-9]{2})([a-fA-F0-9]{2})([a-fA-F0-9]{2})\}(.+)#is'
            ];
            $replace_regex = [
                '<font color="#$3$2$1">$4</font>'
            ];
            $text = preg_replace($search_regex, $replace_regex, $text);

            $srt->addCue($text, SubripCue::ms2tc($cue->getStartMS()), SubripCue::ms2tc($cue->getStopMS()));
        }

        return $srt;
    }

    public static function substationalpha2webvtt(SubstationalphaFile $_ass): WebvttFile
    {
        return self::subrip2webvtt(self::substationalpha2subrip($_ass));
    }

    /* ttml converters */
    public static function ttml2subrip(TtmlFile $_ttml): SubripFile
    {
        $srt = new SubripFile();
        foreach ($_ttml->getCues() as $cue) {
            $text = $cue->getText();

            if (null !== $cue->getStyle()) {

                // global cue style
                $text = self::applyTtmlStyles($text, $_ttml->getStyle($cue->getStyle()));

                // span styles
                $matches = [];
                preg_match_all('#<span[^>]*style="([^>"]+)"[^>]*>(.+)</span>#isU', $text, $matches);
                $spanCount = count($matches[0]);
                if ($spanCount > 0) {
                    for ($i = 0; $i < $spanCount; $i++) {
                        $spanStr     = $matches[0][$i];
                        $spanStyleId = $matches[1][$i];
                        $spanText    = $matches[2][$i];

                        $spanStyle = $_ttml->getStyle($spanStyleId);

                        $textForReplace = self::applyTtmlStyles($spanText, $spanStyle);

                        if ($textForReplace !== $spanText) {
                            $text = str_replace($spanStr, $textForReplace, $text);
                        }
                    }
                }
            }

            if (null !== $cue->getRegion()) {
                // cue region style
                $text = self::applyTtmlStyles($text, $_ttml->getRegion($cue->getRegion()));
            }

            $text = str_ireplace(['<br>', '<br/>', '<br />'], File::UNIX_LINE_ENDING, $text);
            $text = preg_replace('#<\/?span[^>]*>#i', '', $text);

            $cleaningPatterns = [
                '</i>'.File::UNIX_LINE_ENDING.'<i>',
                '</b>'.File::UNIX_LINE_ENDING.'<b>',
                '</u>'.File::UNIX_LINE_ENDING.'<u>'
            ];
            $text = html_entity_decode(str_ireplace($cleaningPatterns, File::UNIX_LINE_ENDING, $text));

            $srt->addCue($text, SubripCue::ms2tc($cue->getStartMS()), SubripCue::ms2tc($cue->getStopMS()));
        }

        return $srt;
    }

    private static function applyTtmlStyles(string $text, array $styles): string
    {
        if (isset($styles['fontStyle']) && 'italic' === $styles['fontStyle']) {
            $text = '<i>'.$text.'</i>';
        }
        if (isset($styles['fontWeight']) && 'bold' === $styles['fontWeight']) {
            $text = '<b>'.$text.'</b>';
        }
        if (isset($styles['textDecoration']) && 'underline' === $styles['textDecoration']) {
            $text = '<u>'.$text.'</u>';
        }

        return $text;
    }

    /**
     * Maps WebVTT cue settings (align, position, line) to an ASS alignment
     * tag ({\anN}) understood by most SRT players. Returns null when the
     * settings match the SRT default (bottom-center) and no tag is needed.
     */
    private static function webvttSettingsToSubripAlignment(WebvttCue $cue)
    {
        $settings = $cue->getSettings();
        if (count($settings) === 0) {
            return null;
        }

        // column: 0 = left, 1 = center, 2 = right
        $column = 1;
        if (isset($settings['align'])) {
            switch ($settings['align']) {
                case 'left':
                case 'start':
                    $column = 0;
                    break;
                case 'right':
                case 'end':
                    $column = 2;
                    break;
            }
        } elseif (isset($settings['position'])) {
            switch ($settings['position']) {
                case 'start':
                    $column = 0;
                    break;
                case 'end':
                    $column = 2;
                    break;
            }
        }

        // row: 0 = top, 1 = middle, 2 = bottom
        $row = 2;
        if (isset($settings['line'])) {
            $line = $settings['line'];
            if ('start' === $line) {
                $row = 0;
            } elseif ('middle' === $line) {
                $row = 1;
            } elseif ('end' === $line) {
                $row = 2;
            } elseif (substr($line, -1) === '%') {
                $pct = (int) $line;
                $row = ($pct < 34) ? 0 : (($pct < 67) ? 1 : 2);
            }
        }

        $alignments = [7, 8, 9, 4, 5, 6, 1, 2, 3];
        $alignment  = $alignments[$row * 3 + $column];

        return ($alignment === 2) ? null : '{\an'.$alignment.'}';
    }

    /**
     * Maps a leading ASS alignment tag ({\anN}) back to WebVTT cue settings
     * and returns the text with the tag removed. Settings matching the
     * WebVTT defaults are not set explicitly.
     */
    private static function subripAlignmentToWebvttSettings(string $text, WebvttCue $cue): string
    {
        $matches = [];
        if (!preg_match('#^\{\\\an([1-9])\}#', $text, $matches)) {
            return $text;
        }

        $map = [
            1 => ['align' => 'left'],
            2 => [],
            3 => ['align' => 'right'],
            4 => ['line' => 'middle', 'align' => 'left'],
            5 => ['line' => 'middle'],
            6 => ['line' => 'middle', 'align' => 'right'],
            7 => ['line' => 'start', 'align' => 'left'],
            8 => ['line' => 'start'],
            9 => ['line' => 'start', 'align' => 'right'],
        ];

        foreach ($map[(int) $matches[1]] as $name => $value) {
            $cue->setSetting($name, $value);
        }

        return substr($text, strlen($matches[0]));
    }

    /**
     * Reports WebVTT cue fields that have no equivalent in the target
     * format. Settings listed in $mappedSettings are considered preserved
     * by the caller and are not reported.
     */
    private static function recordWebvttCueLosses(WebvttCue $cue, int $cueIndex, string $targetFormat, array $mappedSettings): void
    {
        foreach ($cue->getSettings() as $name => $value) {
            if (!in_array($name, $mappedSettings, true)) {
                self::recordLoss($cueIndex, 'settings.'.$name, $value, 'not representable in '.$targetFormat);
            }
        }

        $identifier = $cue->getIdentifier();
        if ($identifier !== null && $identifier !== '') {
            self::recordLoss($cueIndex, 'identifier', $identifier, $targetFormat.' does not support cue identifiers');
        }

        $note = $cue->getNote();
        if ($note !== null && trim($note) !== '') {
            self::recordLoss($cueIndex, 'note', $note, $targetFormat.' does not support cue notes');
        }
    }
}
