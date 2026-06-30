/**
 * DASH MPD test fixtures.
 *
 * Realistic MPD manifests matching the Baander backend StreamManifestController
 * DASH output format with SegmentTemplate + SegmentTimeline.
 */

/** Full MPD with 3 video representations (360p, 720p, 1080p) and uniform 6s segments. */
export const FULL_MPD = `<?xml version="1.0" encoding="utf-8"?>
<MPD xmlns="urn:mpeg:dash:schema:mpd:2011"
     profiles="urn:mpeg:dash:profile:isoff-on-demand:2011"
     type="static"
     mediaPresentationDuration="PT60S">
  <Period>
    <AdaptationSet mimeType="video/mp4" contentType="video">
      <Representation id="job-360p" bandwidth="800000" width="640" height="360"
                      codecs="hvc1.1.6.L93.B0">
        <BaseURL>/api/stream/job-360p/</BaseURL>
        <SegmentTemplate initialization="init.mp4" media="seg_$Number$.m4s">
          <SegmentTimeline>
            <S t="0" d="6000"/>
            <S d="6000"/>
            <S d="6000"/>
            <S d="6000"/>
            <S d="6000"/>
            <S d="6000"/>
            <S d="6000"/>
            <S d="6000"/>
            <S d="6000"/>
            <S d="6000"/>
          </SegmentTimeline>
        </SegmentTemplate>
      </Representation>
      <Representation id="job-720p" bandwidth="2800000" width="1280" height="720"
                      codecs="hvc1.1.6.L93.B0">
        <BaseURL>/api/stream/job-720p/</BaseURL>
        <SegmentTemplate initialization="init.mp4" media="seg_$Number$.m4s">
          <SegmentTimeline>
            <S t="0" d="6000"/>
            <S d="6000"/>
            <S d="6000"/>
            <S d="6000"/>
            <S d="6000"/>
            <S d="6000"/>
            <S d="6000"/>
            <S d="6000"/>
            <S d="6000"/>
            <S d="6000"/>
          </SegmentTimeline>
        </SegmentTemplate>
      </Representation>
      <Representation id="job-1080p" bandwidth="5000000" width="1920" height="1080"
                      codecs="hvc1.1.6.L93.B0">
        <BaseURL>/api/stream/job-1080p/</BaseURL>
        <SegmentTemplate initialization="init.mp4" media="seg_$Number$.m4s">
          <SegmentTimeline>
            <S t="0" d="6000"/>
            <S d="6000"/>
            <S d="6000"/>
            <S d="6000"/>
            <S d="6000"/>
            <S d="6000"/>
            <S d="6000"/>
            <S d="6000"/>
            <S d="6000"/>
            <S d="6000"/>
          </SegmentTimeline>
        </SegmentTemplate>
      </Representation>
    </AdaptationSet>
  </Period>
</MPD>`;

/** MPD with variable-duration segments (progressive encoding). */
export const VARIABLE_DURATION_MPD = `<?xml version="1.0" encoding="utf-8"?>
<MPD xmlns="urn:mpeg:dash:schema:mpd:2011" profiles="urn:mpeg:dash:profile:isoff-on-demand:2011">
  <Period>
    <AdaptationSet mimeType="video/mp4">
      <Representation id="var-rend" bandwidth="1400000" width="854" height="480"
                      codecs="hvc1.1.6.L93.B0">
        <BaseURL>/api/stream/var-rend/</BaseURL>
        <SegmentTemplate initialization="init.mp4" media="seg_$Number$.m4s">
          <SegmentTimeline>
            <S t="0" d="4000"/>
            <S d="6000"/>
            <S d="5500"/>
            <S d="7500"/>
          </SegmentTimeline>
        </SegmentTemplate>
      </Representation>
    </AdaptationSet>
  </Period>
</MPD>`;

/** MPD with no Period element. */
export const EMPTY_MPD = `<?xml version="1.0" encoding="utf-8"?>
<MPD xmlns="urn:mpeg:dash:schema:mpd:2011">
</MPD>`;

/** MPD with Period but no AdaptationSet. */
export const NO_ADAPTATION_MPD = `<?xml version="1.0" encoding="utf-8"?>
<MPD xmlns="urn:mpeg:dash:schema:mpd:2011">
  <Period></Period>
</MPD>`;

/** MPD with AdaptationSet but no Representation. */
export const NO_REPRESENTATION_MPD = `<?xml version="1.0" encoding="utf-8"?>
<MPD xmlns="urn:mpeg:dash:schema:mpd:2011">
  <Period>
    <AdaptationSet mimeType="video/mp4"></AdaptationSet>
  </Period>
</MPD>`;

/** MPD with Representation but no SegmentTemplate. */
export const NO_TEMPLATE_MPD = `<?xml version="1.0" encoding="utf-8"?>
<MPD xmlns="urn:mpeg:dash:schema:mpd:2011">
  <Period>
    <AdaptationSet mimeType="video/mp4">
      <Representation id="no-template" bandwidth="2800000" width="1280" height="720"
                      codecs="hvc1.1.6.L93.B0">
        <BaseURL>/api/stream/no-template/</BaseURL>
      </Representation>
    </AdaptationSet>
  </Period>
</MPD>`;

/** Not XML at all. */
export const INVALID_XML = 'this is not xml at all';

/** MPD with both audio and video AdaptationSets. */
export const MULTI_ADAPTATION_MPD = `<?xml version="1.0" encoding="utf-8"?>
<MPD xmlns="urn:mpeg:dash:schema:mpd:2011">
  <Period>
    <AdaptationSet mimeType="audio/mp4" contentType="audio">
      <Representation id="audio-aac" bandwidth="128000" codecs="mp4a.40.2">
        <BaseURL>/api/stream/audio-aac/</BaseURL>
        <SegmentTemplate initialization="init.mp4" media="seg_$Number$.m4s">
          <SegmentTimeline>
            <S t="0" d="6000"/>
          </SegmentTimeline>
        </SegmentTemplate>
      </Representation>
    </AdaptationSet>
    <AdaptationSet mimeType="video/mp4" contentType="video">
      <Representation id="vid-720" bandwidth="2800000" width="1280" height="720"
                      codecs="hvc1.1.6.L93.B0">
        <BaseURL>/api/stream/vid-720/</BaseURL>
        <SegmentTemplate initialization="init.mp4" media="seg_$Number$.m4s">
          <SegmentTimeline>
            <S t="0" d="6000"/>
            <S d="6000"/>
          </SegmentTimeline>
        </SegmentTemplate>
      </Representation>
    </AdaptationSet>
  </Period>
</MPD>`;
