<?php

namespace App\Modules\Metadata\MediaMeta;

use Exception;

/**
 * This class represents a file containing ID3v1 tags.
 *
 * ID3v1 is a simple tagging format that stores metadata at the end of the audio file.
 * It has fixed-length fields for title, artist, album, year, comment, and genre.
 */
class Id3v1
{
    /**
     * The genre list.
     *
     * @var array
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
        255 => 'Unknown',
    ];
    /** @var string */
    private string $title = '';
    /** @var string */
    private string $artist = '';
    /** @var string */
    private string $album = '';
    /** @var string */
    private string $year = '';
    /** @var string */
    private string $comment = '';
    /** @var integer */
    private int $track = 0;
    /** @var integer */
    private int $genre = 255;
    private string $filePath;

    /**
     * Constructs the Id3v1 class with given file.
     *
     * @param string $filePath The path to the audio file
     * @throws Exception if the file cannot be read or does not contain ID3v1 tags
     */
    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
        $this->parse();
    }

    /**
     * Parses the ID3v1 tag from the file.
     *
     * @throws Exception if the file cannot be read or does not contain ID3v1 tags
     */
    private function parse(): void
    {
        if (!file_exists($this->filePath)) {
            throw new Exception("File not found: {$this->filePath}");
        }

        $handle = fopen($this->filePath, 'rb');
        if (!$handle) {
            throw new Exception("Cannot open file: {$this->filePath}");
        }

        // ID3v1 tag is always at the end of the file and is 128 bytes long
        fseek($handle, -128, SEEK_END);
        $tag = fread($handle, 128);
        fclose($handle);

        // Check if the file has an ID3v1 tag
        if (!str_starts_with($tag, 'TAG')) {
            throw new Exception("File does not contain ID3v1 tag");
        }

        // Parse the tag
        $this->title = trim(substr($tag, 3, 30));
        $this->artist = trim(substr($tag, 33, 30));
        $this->album = trim(substr($tag, 63, 30));
        $this->year = trim(substr($tag, 93, 4));

        // Check for ID3v1.1 (with track number)
        if (ord(substr($tag, 125, 1)) === 0 && ord(substr($tag, 126, 1)) !== 0) {
            $this->comment = trim(substr($tag, 97, 28));
            $this->track = ord(substr($tag, 126, 1));
        } else {
            $this->comment = trim(substr($tag, 97, 30));
            $this->track = 0;
        }

        $this->genre = ord(substr($tag, 127, 1));
    }

    /**
     * Returns the title.
     *
     * @return string
     */
    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * Sets the title.
     *
     * @param string $title The title
     * @return self
     */
    public function setTitle(string $title): self
    {
        $this->title = substr($title, 0, 30);
        return $this;
    }

    /**
     * Returns the artist.
     *
     * @return string
     */
    public function getArtist(): string
    {
        return $this->artist;
    }

    /**
     * Sets the artist.
     *
     * @param string $artist The artist
     * @return self
     */
    public function setArtist(string $artist): self
    {
        $this->artist = substr($artist, 0, 30);
        return $this;
    }

    /**
     * Returns the album.
     *
     * @return string
     */
    public function getAlbum(): string
    {
        return $this->album;
    }

    /**
     * Sets the album.
     *
     * @param string $album The album
     * @return self
     */
    public function setAlbum(string $album): self
    {
        $this->album = substr($album, 0, 30);
        return $this;
    }

    /**
     * Returns the year.
     *
     * @return string
     */
    public function getYear(): string
    {
        return $this->year;
    }

    /**
     * Sets the year.
     *
     * @param string $year The year
     * @return self
     */
    public function setYear(string $year): self
    {
        $this->year = substr($year, 0, 4);
        return $this;
    }

    /**
     * Returns the comment.
     *
     * @return string
     */
    public function getComment(): string
    {
        return $this->comment;
    }

    /**
     * Sets the comment.
     *
     * @param string $comment The comment
     * @return self
     */
    public function setComment(string $comment): self
    {
        $this->comment = substr($comment, 0, 28);
        return $this;
    }

    /**
     * Returns the track number.
     *
     * @return integer
     */
    public function getTrack(): int
    {
        return $this->track;
    }

    /**
     * Sets the track number.
     *
     * @param integer $track The track number
     * @return self
     */
    public function setTrack(int $track): self
    {
        $this->track = min(255, max(0, $track));
        return $this;
    }

    /**
     * Returns the genre.
     *
     * @return string
     */
    public function getGenre(): string
    {
        return self::$genres[$this->genre] ?? 'Unknown';
    }

    /**
     * Sets the genre by name.
     *
     * @param string $genre The genre name
     * @return self
     */
    public function setGenre(string $genre): self
    {
        $genreId = array_search($genre, self::$genres);
        if ($genreId !== false) {
            $this->genre = $genreId;
        }
        return $this;
    }

    /**
     * Returns the genre ID.
     *
     * @return integer
     */
    public function getGenreId(): int
    {
        return $this->genre;
    }

    /**
     * Sets the genre ID.
     *
     * @param integer $genreId The genre ID
     * @return self
     */
    public function setGenreId(int $genreId): self
    {
        $this->genre = min(255, max(0, $genreId));
        return $this;
    }

    /**
     * Writes the ID3v1 tag to the file.
     *
     * @throws Exception if the file cannot be written
     */
    public function write(): void
    {
        $handle = fopen($this->filePath, 'r+b');
        if (!$handle) {
            throw new Exception("Cannot open file for writing: {$this->filePath}");
        }

        // Prepare the tag data
        $tag = 'TAG';
        $tag .= str_pad($this->title, 30, "\0");
        $tag .= str_pad($this->artist, 30, "\0");
        $tag .= str_pad($this->album, 30, "\0");
        $tag .= str_pad($this->year, 4, "\0");

        // Handle ID3v1.1 (with track number)
        if ($this->track > 0) {
            $tag .= str_pad($this->comment, 28, "\0");
            $tag .= "\0";
            $tag .= chr($this->track);
        } else {
            $tag .= str_pad($this->comment, 30, "\0");
        }

        $tag .= chr($this->genre);

        // Check if the file already has an ID3v1 tag
        fseek($handle, -128, SEEK_END);
        $existingTag = fread($handle, 3);

        if ($existingTag === 'TAG') {
            // Overwrite the existing tag
            fseek($handle, -128, SEEK_END);
        } else {
            // Append the tag to the end of the file
            fseek($handle, 0, SEEK_END);
        }

        fwrite($handle, $tag);
        fclose($handle);
    }
}
