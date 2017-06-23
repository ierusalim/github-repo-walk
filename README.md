# github-repo-walk

Very simple class for download repository from GitHub.
_It is not wrapper for "git"_.

Example of use:
```php
require 'github_repo_walk.php'; // or require 'vendor/autoload.php';

$g = new github_repo_walk(
    '<any local path>',
    'ierusalim',
    'git-repo-walk',
    'master'
    );

$g->write_enable(); // or skip for compare remote repository with local copy only

$stat = $g->git_repo_compare_walk(); //doing

print_r($stat);
```

Result: download files from this repository to <any local path>
