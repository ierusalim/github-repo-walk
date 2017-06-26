# github-repo-walk

Simple class for download repository from GitHub.

_It is not wrapper for "git"_.

### Example of use:
```php
namespace ierusalim\GitRepoWalk;

require 'GitRepoWalk.php'; // or require 'vendor/autoload.php';

$g = new GitRepoWalk( 
    '<local path for repository>',
    'ierusalim/github-repo-walk' //git-user and repository in one string
);

$g->write_enable(); // if skip it remote repository will be compare with local

$stat = $g->git_repo_walk(); //download all files from repository to local-path

print_r($stat);
```

Result: download files from this repository to &lt;local path for repository&gt;

### Example 2:

Git repositories list for specified user:
```php

$repo_list_arr = $g->getUserRepositoriesList('php-fig');

print_r($repo_list_arr);
```
Result: get as array repositories list of git-user 'php-fig'
