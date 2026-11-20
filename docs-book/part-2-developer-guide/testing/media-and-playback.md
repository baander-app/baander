# Media and Playback Qualification

Choose the check for the behavior you need to verify. Native decoding, browser
playback, capacity and complete-deployment qualification establish different
results; each page states what its checks qualify.

## Native Segment Encoder Suites

[Native media qualification](native-media.md) covers segment encoding, source
selection, timelines, audio profiles and AAC implementations. These decoded-media
checks do not establish browser playback acceptance.

## Two-Command Deployment and Browser Checkpoint

[Playback lifecycle qualification](playback-lifecycle.md) exercises a production
worker image through playback, seeks, restart and recovery.
[Movie endpoint playback](movie-endpoint-playback.md) checks selected-video
duration, natural completion and replay separately from that full lifecycle.

## Automatic movie quality switching

[Adaptive and source-specific playback](adaptive-playback.md) covers quality
switching, sparse sources, audio delay and audio selection through the isolated
browser runner. Each scenario retains its own format and browser limits.

## Shared media control qualification

[Media control and capacity](media-capacity.md) checks control responsiveness
under pressure and load at default slots. [Encoder cost](media-encoder-cost.md)
separates individual software/hardware measurements and their recorded results
from the deployment load proof.

## Deployment qualification

[Deployment qualification](deployment-qualification.md) measures a whole
disposable deployment on a constrained host or VM, with the observer outside it.
It includes the prerequisites for a published capacity claim.

## Playback Destination Specs

[Playback destination qualification](playback-destinations.md) checks close
reports, override and local recovery across browser contexts. These cases cover
interactions outside the single-page lifecycle drill.
