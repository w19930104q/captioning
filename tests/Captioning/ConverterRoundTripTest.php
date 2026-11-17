<?php

namespace Captioning;

use Captioning\Format\SubripFile;
use Captioning\Format\SubripCue;
use Captioning\Format\WebvttFile;
use Captioning\Format\WebvttCue;
use PHPUnit\Framework\TestCase;

/**
 * Fixed three-format conversion chains (webvtt, subrip, substationalpha)
 * compared through a normalized structural diff: cue order, time
 * boundaries and text lines — plus the loss report for fields that
 * have no equivalent in the target format.
 */
class ConverterRoundTripTest extends TestCase
{
    /**
     * Normalizes a subtitle file to a canonical structure so two files
     * can be diffed regardless of format-specific serialization.
     */
    private function structureOf(FileInterface $file): array
    {
        $structure = [];
        foreach ($file->getCues() as $cue) {
            $structure[] = [
                'start' => $cue->getStartMS(),
                'stop'  => $cue->getStopMS(),
                'lines' => $cue->getTextLines(),
            ];
        }

        usort($structure, function (array $a, array $b) {
            return [$a['start'], $a['stop']] <=> [$b['start'], $b['stop']];
        });

        return $structure;
    }

    private function lossFields(array $losses): array
    {
        return array_map(function ($loss) {
            return $loss['field'];
        }, $losses);
    }

    private function fixedWebvttFile(): WebvttFile
    {
        $content = "WEBVTT\n"
            ."Region: id=fred width=40% lines=3 regionanchor=0%,100% viewportanchor=10%,90% scroll=up\n"
            ."\n"
            ."00:00:01.000 --> 00:00:04.000 align:left\n"
            ."<v Fred><i>Hello</i> there,\n"
            ."second line.\n"
            ."\n"
            ."00:00:03.000 --> 00:00:06.000 line:start align:right\n"
            ."Overlapping <b>bold</b> cue.\n"
            ."\n"
            ."00:00:05.000 --> 00:00:05.000 region:fred\n"
            ."Zero duration cue.\n"
            ."\n"
            ."23:59:58.000 --> 25:00:02.500\n"
            ."Crossing midnight.\n";

        $file = new WebvttFile();
        $file->loadFromString($content);

        return $file;
    }

    public function testThreeFormatRoundTripPreservesStructure()
    {
        $vtt = $this->fixedWebvttFile();

        $srt  = $vtt->convertTo('subrip');
        $ass  = $srt->convertTo('substationalpha');
        $srt2 = $ass->convertTo('subrip');
        $vtt2 = $srt2->convertTo('webvtt');

        // subrip -> substationalpha -> subrip must be structurally lossless
        $this->assertEquals($this->structureOf($srt), $this->structureOf($srt2));

        // the full webvtt -> subrip -> substationalpha -> subrip -> webvtt
        // chain must preserve cue order, time boundaries and text lines
        $this->assertEquals($this->structureOf($vtt), $this->structureOf($vtt2));

        // mappable positioning survives the whole chain as cue settings
        $this->assertEquals(['align' => 'left'], $vtt2->getCue(0)->getSettings());
        $this->assertEquals(['line' => 'start', 'align' => 'right'], $vtt2->getCue(1)->getSettings());
    }

    public function testWebvttSettingsAreMappedToSubripAlignmentTag()
    {
        $srt = $this->fixedWebvttFile()->convertTo('subrip');

        // align:left -> bottom-left, line:start + align:right -> top-right
        $this->assertStringStartsWith('{\an1}', $srt->getCue(0)->getText());
        $this->assertStringStartsWith('{\an9}', $srt->getCue(1)->getText());
        // no positioning info -> no tag
        $this->assertStringNotContainsString('{\an', $srt->getCue(3)->getText());
    }

    public function testSubripAlignmentTagIsMappedToWebvttSettings()
    {
        $srt = new SubripFile();
        $srt->addCue('{\an8}Top centered text', '00:00:01,000', '00:00:02,000');

        $vtt = $srt->convertTo('webvtt');

        $this->assertSame(['line' => 'start'], $vtt->getCue(0)->getSettings());
        $this->assertSame('Top centered text', $vtt->getCue(0)->getText());
    }

    public function testLossyFieldsAreReported()
    {
        $this->fixedWebvttFile()->convertTo('subrip');
        $losses = Converter::getConversionLosses();

        // region definitions and the region setting cannot be represented in subrip
        $this->assertContains('regions', $this->lossFields($losses));
        $this->assertContains('settings.region', $this->lossFields($losses));

        // mappable settings are preserved, not reported as lost
        $this->assertNotContains('settings.align', $this->lossFields($losses));
        $this->assertNotContains('settings.line', $this->lossFields($losses));

        foreach ($losses as $loss) {
            $this->assertArrayHasKey('cue', $loss);
            $this->assertArrayHasKey('value', $loss);
            $this->assertArrayHasKey('reason', $loss);
        }
    }

    public function testLossReportIsResetBetweenConversions()
    {
        $vtt = $this->fixedWebvttFile();
        $vtt->convertTo('subrip');
        $this->assertNotEmpty(Converter::getConversionLosses());

        $srt = new SubripFile();
        $srt->addCue('No positioning here', '00:00:01,000', '00:00:02,000');
        $srt->convertTo('webvtt');
        $this->assertSame([], Converter::getConversionLosses());
    }

    public function testCrossMidnightTimecodesDoNotWrap()
    {
        $this->assertSame('25:00:02,500', SubripCue::ms2tc(90002500));
        $this->assertSame('25:00:02.500', WebvttCue::ms2tc(90002500));

        $srt = $this->fixedWebvttFile()->convertTo('subrip');
        $cue = $srt->getCue(3);

        $this->assertSame(86398000, $cue->getStartMS());
        $this->assertSame(90002500, $cue->getStopMS());
        $this->assertSame('23:59:58,000 --> 25:00:02,500', $cue->getTimeCodeString());
    }

    public function testZeroDurationCueSurvivesConversion()
    {
        $srt = $this->fixedWebvttFile()->convertTo('subrip');
        $cue = $srt->getCue(2);

        $this->assertSame(5000, $cue->getStartMS());
        $this->assertSame(5000, $cue->getStopMS());
        $this->assertSame(0, $cue->getDuration());
        $this->assertSame(0.0, $cue->getCPS());
    }

    public function testCueOrderingIsUnifiedAcrossDigitBoundaries()
    {
        $file = new SubripFile();
        foreach ([10000, 900, 1000, 9999] as $ms) {
            $file->addCue('cue at '.$ms, SubripCue::ms2tc($ms), SubripCue::ms2tc($ms + 1));
        }

        $file->sortCues();

        $starts = array_map(function ($cue) {
            return $cue->getStartMS();
        }, $file->getCues());

        $this->assertSame([900, 1000, 9999, 10000], $starts);
    }

    public function testDefaultConverterReportsWebvttLosses()
    {
        $this->fixedWebvttFile()->convertTo('SBV');
        $losses = $this->lossFields(Converter::getConversionLosses());

        // the fallback adapter maps nothing: every setting is reported
        $this->assertContains('settings.align', $losses);
        $this->assertContains('settings.line', $losses);
        $this->assertContains('settings.region', $losses);
    }
}
