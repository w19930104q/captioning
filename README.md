Captioning [![Build Status](https://secure.travis-ci.org/captioning/captioning.png)](http://travis-ci.org/captioning/captioning) [![Latest Stable Version](https://poser.pugx.org/captioning/captioning/v/stable.svg)](https://packagist.org/packages/captioning/captioning) [![Total Downloads](https://poser.pugx.org/captioning/captioning/downloads.svg)](https://packagist.org/packages/captioning/captioning) [![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/captioning/captioning/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/captioning/captioning/?branch=master)
==========

Captioning is a collection of tools made to help you create and edit subtitles in different formats:

* Subrip (.srt)
* WebVTT (.vtt)
* Substation Alpha (.ass)
* Youtube Subtitles (.sbv)
* JSON (TED.com) Subtitles (.json)
* [WIP] TTML (.dfxp)

# Installation

``` sh
composer require captioning/captioning "2.*"
```

# Usage

Examples and snippets in the [wiki](https://github.com/captioning/captioning/wiki).

## Format conversion

``` php
$vtt = new \Captioning\Format\WebvttFile('captions.vtt');
$srt = $vtt->convertTo('subrip');
$srt->save('captions.srt');
```

Conversion keeps every cue field that has an equivalent in the target
format. WebVTT cue settings (`align`, `position`, `line`) are mapped to
an ASS alignment tag (`{\anN}`) when converting to Subrip, and mapped
back to cue settings when converting from Subrip to WebVTT.

Fields with no equivalent in the target format are dropped but reported;
inspect them after a conversion:

``` php
$vtt->convertTo('subrip');
print_r(\Captioning\Converter::getConversionLosses());
// each entry: ['cue' => int|null, 'field' => string, 'value' => mixed, 'reason' => string]
```


# TODO-list
* [Substation Alpha] add dynamic parsing if events format is not the default one
* [WebVTT] Check if the region defined in WebvttFile object in WebvttCue::checkSetting()
* [TTML] Implement the buildPart method