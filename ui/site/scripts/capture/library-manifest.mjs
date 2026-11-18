// The fictional music library the website pictures are captured from. Every artist, album and
// song name, every lyric line and every cover is invented for this file; nothing here is taken
// from a real release.

const COVER_SIZE = 1000;

const rect = (x, y, width, height, fill) => `<rect x="${x}" y="${y}" width="${width}" height="${height}" fill="${fill}"/>`;
const disc = (cx, cy, r, fill) => `<circle cx="${cx}" cy="${cy}" r="${r}" fill="${fill}"/>`;
const ring = (cx, cy, r, stroke, width) => `<circle cx="${cx}" cy="${cy}" r="${r}" fill="none" stroke="${stroke}" stroke-width="${width}"/>`;
const polygon = (points, fill) => `<polygon points="${points.map(point => point.join(',')).join(' ')}" fill="${fill}"/>`;
const stroke = (x1, y1, x2, y2, color, width) => `<line x1="${x1}" y1="${y1}" x2="${x2}" y2="${y2}" stroke="${color}" stroke-width="${width}" stroke-linecap="round"/>`;
const turned = (degrees, shape) => `<g transform="rotate(${degrees} 500 500)">${shape}</g>`;
const cover = (background, shapes) => `<svg xmlns="http://www.w3.org/2000/svg" width="${COVER_SIZE}" height="${COVER_SIZE}" viewBox="0 0 ${COVER_SIZE} ${COVER_SIZE}">`
  + `${rect(0, 0, COVER_SIZE, COVER_SIZE, background)}${shapes.flat().join('')}</svg>`;
const range = count => Array.from({ length: count }, (_, index) => index);

// One flat geometric design per album.
const covers = {
  // A four by four tiling of quarter discs, each turned a quarter further than its neighbour.
  leafTiles: () => cover('#1f4d2c', range(16).map((index) => {
    const column = index % 4;
    const row = Math.floor(index / 4);
    const x = column * 250;
    const y = row * 250;
    const path = [
      `M${x},${y}h250a250,250 0 0 1 -250,250z`,
      `M${x + 250},${y}v250a250,250 0 0 1 -250,-250z`,
      `M${x + 250},${y + 250}h-250a250,250 0 0 1 250,-250z`,
      `M${x},${y + 250}v-250a250,250 0 0 1 250,250z`,
    ][(column + row) % 4];
    return `<path d="${path}" fill="${['#5fa84a', '#8ccf5d', '#2f7a3d'][(column + 2 * row) % 3]}"/>`;
  })),
  // A low sun behind stacked bands of weather.
  weather: () => cover('#f3e6d0', [
    disc(680, 360, 210, '#d9694a'),
    rect(0, 430, 1000, 110, '#b9889c'),
    rect(0, 540, 1000, 70, '#f3e6d0'),
    rect(0, 610, 1000, 150, '#7d4a68'),
    rect(0, 760, 1000, 60, '#b9889c'),
    rect(0, 820, 1000, 180, '#4b2b45'),
  ]),
  // Six round petals around a centre.
  flower: () => cover('#e8d5b5', [
    range(6).map(index => turned(index * 60, disc(500, 290, 170, '#e8749a'))),
    disc(500, 500, 120, '#f6c445'),
  ]),
  // A row of firs on snow under a small moon.
  pines: () => cover('#c9d8e6', [
    disc(790, 210, 70, '#f7f4ec'),
    [[120, 420, 150], [300, 300, 190], [500, 380, 170], [690, 260, 200], [880, 440, 140]]
      .map(([x, top, half], index) => polygon([[x, top], [x + half, 780], [x - half, 780]], index % 2 ? '#1f4a4a' : '#2f6b5a')),
    rect(0, 780, 1000, 220, '#f7f4ec'),
  ]),
  // Rings of light spreading from the quay's corner, and one lantern.
  quay: () => cover('#16284a', [
    [840, 660, 480, 300].map((radius, index) => ring(0, 1000, radius, ['#f4d35e', '#3fa7a3', '#f7f4ec', '#f4d35e'][index], 60)),
    disc(780, 220, 80, '#f4d35e'),
  ]),
  // A moon over five rails.
  transit: () => cover('#2b2a6b', [
    disc(640, 360, 230, '#8f8be0'),
    disc(300, 240, 26, '#f7f4ec'),
    range(5).map(index => stroke(-50, 620 + index * 90, 1050, 500 + index * 90, index % 2 ? '#8f8be0' : '#5552b8', 26)),
  ]),
  // Six lines of swell under a pale sun.
  swell: () => cover('#1f8a9b', [
    disc(730, 250, 120, '#d8f1ee'),
    range(6).map(index => `<path d="M-60,${470 + index * 85}q140,${index % 2 ? 60 : -60} 280,0t280,0t280,0t280,0" fill="none" `
      + `stroke="${index % 2 ? '#8fd3d0' : '#d8f1ee'}" stroke-width="22" stroke-linecap="round"/>`),
  ]),
  // A sealed envelope.
  envelope: () => cover('#cfdbe8', [
    rect(150, 290, 700, 460, '#f7f4ec'),
    polygon([[150, 290], [850, 290], [500, 560]], '#c8402f'),
    disc(500, 560, 46, '#7d1f17'),
  ]),
  // Bars of uneven length, like a dial between stations.
  signal: () => cover('#f2b233', [[80, 620], [240, 700], [80, 380], [400, 520], [160, 760], [80, 300], [320, 600], [200, 480], [80, 700]]
    .map(([x, width], index) => `<rect x="${x}" y="${95 + index * 92}" width="${width}" height="54" rx="27" fill="${index % 3 === 1 ? '#fbe9bd' : '#5a3410'}"/>`)),
  // A sun going down behind bands of shallow water.
  lowTide: () => cover('#1d2a4d', [
    disc(500, 560, 260, '#f2793a'),
    rect(0, 560, 1000, 90, '#e2566b'),
    rect(0, 650, 1000, 90, '#8e3f77'),
    rect(0, 740, 1000, 90, '#4a3a78'),
    rect(0, 830, 1000, 170, '#1d2a4d'),
  ]),
  // Nine columns of uneven height.
  skyline: () => cover('#14306b', [420, 640, 300, 520, 760, 360, 580, 240, 460]
    .map((height, index) => rect(80 + index * 96, 920 - height, 72, height, ['#3f7fe0', '#86b6f6', '#2456b3'][index % 3]))),
  // Five steps climbing towards a lamp.
  stairs: () => cover('#d9a441', [
    polygon([[0, 1000], [0, 820], [200, 820], [200, 660], [400, 660], [400, 500], [600, 500], [600, 340], [800, 340], [800, 180], [1000, 180], [1000, 1000]], '#2a1c12'),
    disc(230, 250, 110, '#f7edd2'),
  ]),
  // Two sets of contour rings.
  contours: () => cover('#10343f', [
    range(6).map(index => ring(400, 590, 80 + index * 80, '#35e0d0', 14)),
    range(4).map(index => ring(740, 280, 60 + index * 70, '#ff4fa3', 14)),
  ]),
  // Sixteen rays around a hub.
  sunburst: () => cover('#7a2e12', [
    range(16).map(index => turned(index * 22.5, rect(470, 60, 60, 300, index % 2 ? '#f5a623' : '#fbd27a'))),
    disc(500, 500, 150, '#f5a623'),
    disc(500, 500, 70, '#7a2e12'),
  ]),
  // Three tilted orbits, each carrying a square of paper.
  orbits: () => cover('#5b3fa0', [
    [[-30, 420, 150], [25, 330, 230], [70, 250, 110]].map(([degrees, rx, ry]) => turned(degrees, [
      `<ellipse cx="500" cy="500" rx="${rx}" ry="${ry}" fill="none" stroke="#b9a5ec" stroke-width="12"/>`,
      rect(470 + rx, 470, 60, 60, '#f6e58d'),
    ].join(''))),
    disc(500, 500, 90, '#f7f4ec'),
  ]),
};

const verseChorus = (first, chorus, second, bridge) => [first, chorus, second, chorus, bridge, chorus];

const lyrics = {
  glassHarbour: verseChorus(
    ['Morning lays the water flat as glass', 'Boats asleep with their names turned to the wall', 'I count the masts like candles on a cake', 'And wish on every one before they fall'],
    ['Oh, glass harbour, hold still for me', 'Keep the sky where I can reach', 'If I break you with a pebble', 'Mend yourself before the tide can teach'],
    ['The fishmonger hums a tune he doesn\'t know', 'The gulls rehearse their arguments again', 'I fold your letter small enough to float', 'And set it sailing past the harbour men'],
    ['Nothing here is deep, only clear', 'Nothing here is gone, only near'],
  ),
  northernLights: verseChorus(
    ['We drove until the radio gave up', 'You said the sky owed us a small display', 'Green ribbon, thin as breath on window glass', 'It came, it bowed, it went the other way'],
    ['Northern lights, briefly', 'Long enough to learn your face by', 'Northern lights, briefly', 'Short enough to keep'],
    ['The thermos lid became a tiny moon', 'Your mittens kept the shape of holding on', 'We clapped for nothing like it was a show', 'And nothing took its curtain call at dawn'],
    ['Some things only stay if you don\'t stare', 'I looked away, and you were still there'],
  ),
  saltOnTheWindowpane: verseChorus(
    ['The storm wrote all night in a careful hand', 'By morning every window wore its lace', 'I read the weather\'s letter with my thumb', 'And tasted where the sea had touched the place'],
    ['Salt on the windowpane', 'Proof that something travelled far', 'Salt on the windowpane', 'Small white maps of where we are'],
    ['My grandmother would rinse it with the tea', 'And say the house was seasoned, not unclean', 'I leave it till the afternoon is gold', 'And see the harbour through a sugared screen'],
    ['Wipe it off, it comes again', 'Like a friend, like a friend'],
  ),
  keeperOfTheSmallLight: verseChorus(
    ['Not the tower on the postcard, not the beam', 'Just a lantern on a hook above the pier', 'He trims it when the ferries fall asleep', 'So the last boat home will know the wood is near'],
    ['Keeper of the small light', 'Nobody writes your name in brass', 'Keeper of the small light', 'You taught the dark some manners as it passed'],
    ['He has a chair, a kettle and a coat', 'A crossword he has never thought to end', 'He says the sea is mostly being patient', 'And patience is a thing that you can lend'],
    ['When I am lost by inches, not by miles', 'It\'s the small light that I find'],
  ),
  boatsWeDidntTake: verseChorus(
    ['There was one for the islands, painted red', 'There was one that only ran on market days', 'We stood there with our tickets in our teeth', 'And watched them shrink to commas in the haze'],
    ['Here\'s to the boats we didn\'t take', 'The ones that left on time', 'They carried someone else\'s summer', 'And I was glad to stay with mine'],
    ['You bought us chips and called it a cruise', 'The bench was our upper deck, the gull our crew', 'I never saw the islands, that is true', 'But I have seen the harbour, and seen you'],
    ['Every map has a folded side', 'We lived on the fold, and it was wide'],
  ),
  mooringLines: verseChorus(
    ['Loop it twice and let the third one rest', 'My father showed me once, then looked away', 'A knot is just a promise made of rope', 'It holds as long as both ends want to stay'],
    ['Mooring lines, mooring lines', 'Slack enough to breathe', 'Mooring lines, mooring lines', 'Tight enough to keep'],
    ['The tide will lift us up without a word', 'The tide will set us down the same', 'I check the cleat at midnight in my socks', 'And say the boat\'s and then my father\'s name'],
    ['Let the water do the moving', 'Let the rope do the remembering'],
  ),
  lateBloom: verseChorus(
    ['Everybody flowered in the spring', 'I was busy learning how to root', 'They said the season closed its doors on me', 'I said I\'m only tuning up the fruit'],
    ['Call me a late bloom, baby', 'I open when I\'m good and warm', 'Call me a late bloom, baby', 'The best light comes after the storm'],
    ['My mama kept a plant behind the stove', 'That sulked for seven years and then went wild', 'She\'d tap the pot and tell it take your time', 'And look at me, and look at it, and smile'],
    ['No clock on a petal', 'No hurry in a seed'],
  ),
  honeyAndAsh: verseChorus(
    ['You left the kitchen smelling like July', 'A spoon still standing in the open jar', 'I struck a match to see what would remain', 'And learned how sweet and smoke together are'],
    ['Honey and ash, honey and ash', 'What we were and what is left', 'Honey and ash, honey and ash', 'I keep a little of them both'],
    ['The neighbours ask me how I\'m getting on', 'I tell them slow and golden, mostly grey', 'I stir the two together in my tea', 'And drink to you and send the cup away'],
    ['Sweet don\'t cancel bitter', 'It only sits beside'],
  ),
  borrowedSunday: verseChorus(
    ['I took a day that wasn\'t on my card', 'Left the phone face down beside the sink', 'Wore your shirt and walked the long way round', 'And bought myself a flower and a drink'],
    ['It\'s a borrowed Sunday', 'I\'ll pay it back in time', 'It\'s a borrowed Sunday', 'Today the whole slow thing is mine'],
    ['The bus went by, I waved it like a friend', 'The bells went on about the things I owe', 'I sat down on the steps and counted clouds', 'And every one of them said take it slow'],
    ['Interest is a problem for Monday', 'Monday isn\'t here'],
  ),
  slowToOpen: verseChorus(
    ['Don\'t knock so hard, the hinges need a song', 'Don\'t bring a crowbar where a key will do', 'I\'ve got a door that sticks in rainy weather', 'It isn\'t locked, it\'s only thinking through'],
    ['I\'m slow to open', 'But I stay open wide', 'I\'m slow to open', 'Come sit a while outside'],
    ['You brought a chair and didn\'t say a word', 'You read the paper backwards to the start', 'Somewhere between the weather and the sports', 'I heard the latch give up inside my heart'],
    ['Patience is a kind of knocking too', 'Knock like that'],
  ),
  perennial: verseChorus(
    ['They cut me to the ground in late November', 'And swept what I had been into a sack', 'I slept beneath the ordinary snow', 'And practised, in the dark, the coming back'],
    ['Perennial, perennial', 'You don\'t have to plant me twice', 'Perennial, perennial', 'I remember where the light is'],
    ['So write me down as absent, not as gone', 'And leave a little room along the wall', 'By April I\'ll be taller than your doubt', 'By June you won\'t recall the fall at all'],
    ['Same root, new green', 'Same song, new key'],
  ),
  paperSatellites: verseChorus(
    ['We cut them out of last year\'s calendar', 'And hung them from the lamp with sewing thread', 'You named them after streets where we had lived', 'They turned above the middle of the bed'],
    ['Paper satellites, going nowhere fast', 'Round and round the bulb they go', 'Paper satellites, built to never last', 'Still the best ones that I know'],
    ['The window breathed and all of them took fright', 'One lost a wing against the bedroom door', 'You taped it, signed the patch, and sent it up', 'It flew a little crooked, nothing more'],
    ['Mission control is a kitchen chair', 'Countdown is you, laughing'],
  ),
  orbitingHome: verseChorus(
    ['I take the long way round the block again', 'Past the bakery, the bike shop and the bins', 'Your window is the bright one on the third', 'I\'m not ready for the part where it begins'],
    ['So I\'m orbiting home', 'One more lap before I land', 'Orbiting home', 'With the keys warm in my hand'],
    ['The dog across the road knows what I do', 'He\'s counted every circuit that I\'ve made', 'He wags as if to say just ring the bell', 'I tell him I\'m adjusting my approach'],
    ['Gravity is patient', 'So are you'],
  ),
  tinCanTelephone: verseChorus(
    ['String between your balcony and mine', 'Two soup tins with the labels half peeled off', 'You said hello and all I heard was sea', 'I said it back and you heard someone cough'],
    ['Tin can telephone, pull it tight', 'Say the small things down the line', 'Tin can telephone, late at night', 'Your static sounds like mine'],
    ['The neighbours think it\'s washing we forgot', 'The pigeons use it as a place to stand', 'And when it rains the words come through as drops', 'I catch them one by one inside my hand'],
    ['No signal, no bill, no battery', 'Just string and you and me'],
  ),
  reEntry: verseChorus(
    ['The party lights go out along the fence', 'The paper cups lie down upon the grass', 'We float a moment in the open gate', 'And let the last of everybody pass'],
    ['Re-entry, re-entry', 'Coming back to ordinary air', 'Re-entry, re-entry', 'Glad to find you landed there'],
    ['The dishes orbit slowly in the sink', 'The clock resumes its talking on the shelf', 'You hand me half a sandwich like a medal', 'And every star goes back to being itself'],
    ['Hold the rail, the ground is turning', 'It always was'],
  ),
  overgrown: verseChorus(
    ['We meant to cut it back the second year', 'We meant to paint the shed and mend the wall', 'The bramble made a better plan than ours', 'And now the garden doesn\'t end at all'],
    ['Overgrown, overgrown', 'Let the green do what it will', 'Overgrown, overgrown', 'We are in the middle still'],
    ['A wren has moved into the watering can', 'The bench has got a jacket made of moss', 'You laughed and hung the shears up on a nail', 'And called it gaining what we thought was loss'],
    ['Nobody tidy ever found a nest', 'Leave the corner wild'],
  ),
  theGateLeftOpen: verseChorus(
    ['Somebody forgot, or somebody chose', 'The latch is hanging quiet on its pin', 'The field beyond is leaning on the post', 'As if it has been waiting to come in'],
    ['The gate left open, the gate left wide', 'The lane runs on without a sound', 'The gate left open, and I\'m beside', 'One foot on either ground'],
    ['The sheep walk through like guests who know the house', 'The evening follows after, slow and red', 'I do not close it, and I do not go', 'I lean there with the daylight on my head'],
    ['A door is only wood until you choose', 'So I am choosing slow'],
  ),
  whatTheIvyKeeps: verseChorus(
    ['A ladder nobody has thought to move', 'A window that was painted shut in June', 'A swallow\'s nest, a clothes peg and a name', 'Scratched in the brick below the afternoon'],
    ['That\'s what the ivy keeps', 'Under all its little hands', 'That\'s what the ivy keeps', 'Until somebody understands'],
    ['We lifted up a corner like a page', 'And read a summer neither of us had', 'Then pressed the leaves back gently on the wall', 'The way you fold a letter from your dad'],
    ['Green is slow remembering', 'Leave it where it grows'],
  ),
};

// Each track is [title, length as minutes:seconds, lyric stanzas or the steady-signal mark].
const STEADY = 'steady';
const albums = [
  ['Fern & Fable', 'Overgrown', 2019, 'Folk', covers.leafTiles, [
    ['Moss on the Lintel', '3:12'],
    ['Overgrown', '4:21', lyrics.overgrown],
    ['The Gate Left Open', '2:58', lyrics.theGateLeftOpen],
    ['Bramble Arithmetic', '3:36'],
    ['What the Ivy Keeps', '4:02', lyrics.whatTheIvyKeeps],
    ['Fallow Year', '4:38'],
  ]],
  ['Ivy Marsh', 'Slow Weather', 2021, 'Soul', covers.weather, [
    ['Slow Weather', '3:48'],
    ['Porch Light Promise', '3:15'],
    ['Easy on the Hinges', '4:10'],
    ['Sugar in the Rain Gauge', '2:52'],
    ['Second Sunday', '3:33'],
    ['Leave the Kettle On', '4:07'],
  ]],
  ['Juno Vale', 'Late Bloom', 2022, 'Soul', covers.flower, [
    ['Late Bloom', '3:25', lyrics.lateBloom],
    ['Honey & Ash', '3:42', lyrics.honeyAndAsh],
    ['Borrowed Sunday', '3:02', lyrics.borrowedSunday],
    ['Greenhouse Heart', '4:16'],
    ['Slow to Open', '3:39', lyrics.slowToOpen],
    ['Windowsill', '2:47'],
    ['After the Frost', '3:28'],
    ['Perennial', '3:51', lyrics.perennial],
  ]],
  ['Kestrel & Pine', 'Field Notes for Winter', 2014, 'Folk', covers.pines, [
    ['First Frost Survey', '2:49'],
    ['Kestrel Over the Low Field', '3:27'],
    ['Pine Tar and Twine', '3:05'],
    ['The Long Fence', '4:13'],
    ['Lamp in the Sheepfold', '3:54'],
    ['Thaw Ledger', '4:20'],
  ]],
  ['Lantern Quay', 'Ferry Hours', 2016, 'Indie', covers.quay, [
    ['Ferry Hours', '3:31'],
    ['Ticket for the Late Crossing', '3:58'],
    ['Rope and Rust', '2:55'],
    ['Seawall Postcards', '4:08'],
    ['Tide Tables', '3:19'],
    ['Last Boat Lullaby', '4:24'],
  ]],
  ['Lumen District', 'Midnight Transit', 2020, 'Electronic', covers.transit, [
    ['Platform Glow', '3:44'],
    ['Midnight Transit', '5:12'],
    ['Turnstile Echo', '3:21'],
    ['Standing Wave', '3:30', STEADY],
    ['Last Carriage Lights', '4:36'],
    ['Underpass Bloom', '3:50'],
    ['Depot at Dawn', '5:04'],
  ]],
  ['Mira Solberg', 'Glass Harbour', 2018, 'Folk', covers.swell, [
    ['Glass Harbour', '4:03', lyrics.glassHarbour],
    ['Northern Lights, Briefly', '3:18', lyrics.northernLights],
    ['Salt on the Windowpane', '3:40', lyrics.saltOnTheWindowpane],
    ['Keeper of the Small Light', '4:22', lyrics.keeperOfTheSmallLight],
    ['Boats We Didn\'t Take', '3:09', lyrics.boatsWeDidntTake],
    ['Mooring Lines', '4:27', lyrics.mooringLines],
  ]],
  ['Mira Solberg', 'Winter Letters', 2023, 'Indie', covers.envelope, [
    ['Postmarked December', '3:22'],
    ['Ink Freezes First', '2:57'],
    ['Letter I Didn\'t Send', '4:11'],
    ['Return to Sender, Kindly', '3:45'],
  ]],
  ['Northbound Static', 'Golden Static', 2011, 'Rock', covers.signal, [
    ['Signal Fires', '3:34'],
    ['Golden Static', '4:18'],
    ['Dial Tone Summer', '3:07'],
    ['Antenna Hill', '3:52'],
    ['Shortwave Hearts', '2:46'],
    ['Frequency of Leaving', '4:42'],
  ]],
  ['Northbound Static', 'Low Tide Radio', 2015, 'Rock', covers.lowTide, [
    ['Undertow', '3:07'],
    ['Low Tide Radio', '3:59'],
    ['Salt Wire', '2:51'],
    ['Breakwater', '3:38'],
    ['Driftwood Transmitter', '4:25'],
    ['Gulls on the Aerial', '3:14'],
    ['Northbound Again', '3:46'],
    ['Static Bloom', '4:11'],
  ]],
  ['Ola Brandt Trio', 'Brushes on Slate', 2009, 'Jazz', covers.skyline, [
    ['Hat on the Radiator', '4:58'],
    ['Tram No. 6', '4:07'],
    ['Brushes on Slate', '3:49'],
    ['Ola\'s Waltz', '4:33'],
    ['Quarter Past Rain', '5:05'],
    ['Coat Check Blues', '3:28'],
  ]],
  ['Ola Brandt Trio', 'Stairwell Ballads', 2017, 'Jazz', covers.stairs, [
    ['Third Floor Landing', '4:52'],
    ['Ballad for a Borrowed Piano', '5:27'],
    ['Mail Slot', '3:16'],
    ['Bannister Slide', '2:54'],
    ['Lights Out on Five', '4:41'],
  ]],
  ['Sable Avenue', 'Neon Cartography', 2024, 'Electronic', covers.contours, [
    ['Legend', '2:36'],
    ['Neon Cartography', '4:28'],
    ['Contour Lines', '3:43'],
    ['Grid North', '3:57'],
    ['Night Bus Isobars', '4:14'],
    ['Folded Map of You', '4:49'],
  ]],
  ['The Marigold Exchange', 'Sunday Engine', 2005, 'Soul', covers.sunburst, [
    ['Sunday Engine', '3:41'],
    ['Brass Tacks & Velvet', '3:13'],
    ['Double Shift Love', '4:02'],
    ['Corner Store Gospel', '3:56'],
    ['Keep the Meter Running', '2:48'],
    ['Marigold Hour', '4:35'],
  ]],
  ['The Quiet Almanac', 'Paper Satellites', 2017, 'Indie', covers.orbits, [
    ['Paper Satellites', '3:51', lyrics.paperSatellites],
    ['Orbiting Home', '2:56', lyrics.orbitingHome],
    ['Almanac for Small Hours', '3:24'],
    ['Tin Can Telephone', '3:02', lyrics.tinCanTelephone],
    ['Apogee, Quietly', '4:17'],
    ['Re-entry', '4:34', lyrics.reEntry],
  ]],
];

const seconds = (length) => {
  const [minutes, rest] = length.split(':').map(Number);
  return minutes * 60 + rest;
};

const stamp = (time) => {
  const hundredths = Math.round(time * 100);
  const pad = value => String(value).padStart(2, '0');
  return `${pad(Math.floor(hundredths / 6000))}:${pad(Math.floor(hundredths / 100) % 60)}.${pad(hundredths % 100)}`;
};

/**
 * The synced lyrics of a track as `.lrc` text. The lines are spread evenly over the middle of the
 * track, after an instrumental opening and before an instrumental ending; the break between two
 * stanzas takes as long as one line.
 */
function lrcText(stanzas, duration) {
  const slots = stanzas.flatMap((stanza, index) => (index === 0 ? stanza : [null, ...stanza]));
  const first = duration * 0.08;
  const step = (duration * 0.8) / slots.length;
  return slots
    .flatMap((line, index) => (line === null ? [] : [`[${stamp(first + index * step)}]${line}`]))
    .join('\n') + '\n';
}

/**
 * The whole library: its genres, its artists, and each album with its cover as SVG and its
 * tracks. Paths are relative to the library root. A track with `steady` carries the constant
 * multi-tone signal instead of a tune; a track with `lyrics` gets an `.lrc` file beside it.
 */
export function buildManifest() {
  const built = albums.map(([artist, title, year, genre, design, tracks]) => ({
    artist,
    title,
    year,
    genre,
    directory: `${artist}/${title}`,
    coverSvg: design(),
    tracks: tracks.map(([trackTitle, length, extra], index) => {
      const base = `${artist}/${title}/${String(index + 1).padStart(2, '0')} ${trackTitle}`;
      const duration = seconds(length);
      return {
        number: index + 1,
        title: trackTitle,
        duration,
        path: `${base}.flac`,
        ...(extra === STEADY ? { steady: true } : {}),
        ...(Array.isArray(extra) ? { lyrics: { path: `${base}.lrc`, text: lrcText(extra, duration) } } : {}),
      };
    }),
  }));

  return {
    genres: [...new Set(built.map(album => album.genre))].sort(),
    artists: [...new Set(built.map(album => album.artist))],
    albums: built,
  };
}
