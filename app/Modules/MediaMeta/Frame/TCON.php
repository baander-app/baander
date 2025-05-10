<?php

namespace App\Modules\MediaMeta\Frame;

use App\Modules\MediaMeta\Encoding;

/**
 * TCON frame - Content type (genre).
 *
 * The 'Content type' frame represents the genre of the audio file.
 * In ID3v1, this was a numeric value, but in ID3v2 it can be any string.
 * For backward compatibility, the ID3v1 genres can be used by enclosing
 * the genre number in parentheses, e.g. "(4)" for "Disco".
 */
class TCON extends TextFrame
{
    /**
     * The list of ID3v1 genres.
     *
     * @var array<string>
     */
    public static array $genres = [
        'Blues',
        'Classic Rock',
        'Country',
        'Dance',
        'Disco',
        'Funk',
        'Grunge',
        'Hip-Hop',
        'Jazz',
        'Metal',
        'New Age',
        'Oldies',
        'Other',
        'Pop',
        'R&B',
        'Rap',
        'Reggae',
        'Rock',
        'Techno',
        'Industrial',
        'Alternative',
        'Ska',
        'Death Metal',
        'Pranks',
        'Soundtrack',
        'Euro-Techno',
        'Ambient',
        'Trip-Hop',
        'Vocal',
        'Jazz+Funk',
        'Fusion',
        'Trance',
        'Classical',
        'Instrumental',
        'Acid',
        'House',
        'Game',
        'Sound Clip',
        'Gospel',
        'Noise',
        'AlternRock',
        'Bass',
        'Soul',
        'Punk',
        'Space',
        'Meditative',
        'Instrumental Pop',
        'Instrumental Rock',
        'Ethnic',
        'Gothic',
        'Darkwave',
        'Techno-Industrial',
        'Electronic',
        'Pop-Folk',
        'Eurodance',
        'Dream',
        'Southern Rock',
        'Comedy',
        'Cult',
        'Gangsta',
        'Top 40',
        'Christian Rap',
        'Pop/Funk',
        'Jungle',
        'Native American',
        'Cabaret',
        'New Wave',
        'Psychadelic',
        'Rave',
        'Showtunes',
        'Trailer',
        'Lo-Fi',
        'Tribal',
        'Acid Punk',
        'Acid Jazz',
        'Polka',
        'Retro',
        'Musical',
        'Rock & Roll',
        'Hard Rock',
        'Folk',
        'Folk-Rock',
        'National Folk',
        'Swing',
        'Fast Fusion',
        'Bebob',
        'Latin',
        'Revival',
        'Celtic',
        'Bluegrass',
        'Avantgarde',
        'Gothic Rock',
        'Progressive Rock',
        'Psychedelic Rock',
        'Symphonic Rock',
        'Slow Rock',
        'Big Band',
        'Chorus',
        'Easy Listening',
        'Acoustic',
        'Humour',
        'Speech',
        'Chanson',
        'Opera',
        'Chamber Music',
        'Sonata',
        'Symphony',
        'Booty Bass',
        'Primus',
        'Porn Groove',
        'Satire',
        'Slow Jam',
        'Club',
        'Tango',
        'Samba',
        'Folklore',
        'Ballad',
        'Power Ballad',
        'Rhythmic Soul',
        'Freestyle',
        'Duet',
        'Punk Rock',
        'Drum Solo',
        'A capella',
        'Euro-House',
        'Dance Hall',
    ];

    /**
     * Constructs the TCON frame with given parameters.
     *
     * @param string $genre The genre
     * @param int $encoding The text encoding
     */
    public function __construct(string $genre = '', int $encoding = Encoding::UTF8)
    {
        parent::__construct('TCON', $genre, $encoding);
    }

    /**
     * Returns the genre.
     *
     * @return string
     */
    public function getGenre(): string
    {
        $genre = $this->getText();

        // Check if the genre is in the ID3v1 format (e.g. "(4)")
        if (preg_match('/^\((\d+)\)$/', $genre, $matches)) {
            $genreId = (int)$matches[1];
            if (isset(self::$genres[$genreId])) {
                return self::$genres[$genreId];
            }
        }

        return $genre;
    }

    /**
     * Sets the genre.
     *
     * @param string $genre The genre
     * @return self
     */
    public function setGenre(string $genre): self
    {
        return $this->setText($genre);
    }

    /**
     * Sets the genre by ID3v1 genre ID.
     *
     * @param int $genreId The genre ID
     * @return self
     */
    public function setGenreById(int $genreId): self
    {
        if (isset(self::$genres[$genreId])) {
            return $this->setText("({$genreId})");
        }

        return $this->setText('');
    }
}