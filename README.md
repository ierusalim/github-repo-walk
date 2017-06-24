# github-repo-walk

Simple class for download repository from GitHub.

_It is not wrapper for "git"_.

### Example of use:
```php
namespace ierusalim\gitAPIwork;
require 'github_repo_walk.php'; // or require 'vendor/autoload.php';

$g = new github_repo_walk( 
    '<local path for repository>',
    'ierusalim/github-repo-walk' //git-user and repository
);

$g->write_enable(); // skip it for compare remote repository with local copy

$stat = $g->git_repo_walk(); //download all files from repository to local-path

print_r($stat);
```

Result: download files from this repository to &lt;local path for repository&gt;

### Example 2:

Git repositories list for specified user:
```php
namespace ierusalim\gitAPIwork;
require 'github_repo_walk.php'; // or require 'vendor/autoload.php';

$g = new github_repo_walk();

$repo_arr = $g->git_user_repositories_list('php-fig');

print_r($repo_arr);
```
Result: get as array repositories list of git-user 'php-fig'
