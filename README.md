# GenreTagger
A simple PHP application to add genre tags from last.fm to FLAC music.

## Usage
```bash
git clone https://github.com/pp3345/GenreTagger
cd GenreTagger
echo "my_last.fm_api_key" > key
composer install
php GenreTagger.php [--no-retag] <path-to-collection>
```

By default, GenreTagger will remove all existing genre tags, use the `--no-retag` option to prevent that. Collection is expected to be organized in a `/<artist>/<album>/<track>.flac` hierarchy.
