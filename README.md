Introduction
============

What is the eZ Extension Builder?
---------------------------------

A set of php files and tools to "build" eZ Publish extensions and deliverables.
It is based on the Pake tool.

The build process consists currently of the following steps:
- getting a copy of the latest versions of the source code from the svn/git repository
- executing a series of conformity checks (eg. licensing info files must be in place)
- replacing some token strings inside some specific files (eg. version nr. and licensing info)
- generating end-user documentation from documentation sources (eg.html from rst files)
- creating tarballs of the extension

The steps are implemented via "pake tasks".
Many other tasks are available as well - making this a swiss-army-knife tool of Quality Assurance as well as build.

License
-------

This software is licensed under the GNU General Public License v2.0 . The
complete license agreement is included in the LICENSE file. For more information
or questions please contact info@ez.no

Requirements
------------

- the php cli
- command line tools: svn and/or git, doxygen, tar, zip
- Pake version 1.7.4 or later.
  You can get it either from https://github.com/indeyets/pake
  or as part of the extension itself, using composer installation
- other php tools and libraries: all dependencies are managed automatically through composer

4. Installing
-------------

Read the INSTALL file to get started and for usage instructions


Directory structure
-------------------

Let's call ROOT_DIR the directory where you will be running the build script.
This directory will usually be the top-level directory of your extension, but
it can in fact be anywhere else.::

    ROOT_DIR/
     |___pake        the directory where the configuration file used for the build is expected to be
     |   |
     |   `___options-myext.yaml the configuration file
     |
     |___build/myext  a copy of the extension myext will be downloaded here during the build
     |                NB: if you plan to produce an ezpackage out of the extension,
     |                this directory will change to build/ezextension/<myextension>
     |
     |___dist/        tarballs produced by the build will be made available here
     |
     `___vendor/gggeek/ezextensionbuilder
         |
         |___classes/               php classes with the bulk of the business logic for this tool
         |___doc/                   more documentation, such as changelogs, todos and known bugs
         |___INSTALL                installation instructions
         |___LICENSE                license file
         |___README                 this file
         |___composer.json          configuration file for composer
         |___doxyfile_master        configuration file used when the generate-documentation task is set to create api docs via doxygen
         |___ezextbuilder           a shell-script wrapping execution of pakefile.php
         |___options-ezextensionbuilder.yaml configuration file used to build this extension itself
         |___options-sample.yaml    a sample configuration file
         |___package_master.xml     template file used by the generate-sample-package-file task
         |___pakefile.php           the main build script
         `___pakefile_bootstrap.php a php file used by the build script

As you can see, we try to pollute as little as possible the ROOT_DIR: everything
is neatly stowed away in the vendor, pake, build and dist subdirectories.


FAQ
---

- Can a standalone copy of pake be used with the pakefile instead of the bundled one?
    Yes: just use a different command line: ::
    pake -f ./vendor/gggeek/ezextensionbuilder/pakefile.php build myext

- Can multiple extensions be built in the same ROOT_DIR?
    Yes. Just create an option file for each

- Can the script use my ancient configuration files used with the previous ant-based version?
    Yes. Use the convert-configuration task for converting them to the new format

- Can I download a complete zip of the tool instead of installing via composer?
    Maybe. But you will have to test by yourself if all include_path and autoloading stuff works

- Interaction with git/svn: which files to commit in my repo, which ones not?
    If you plan to build in the root dir of your extension, you can very easily
    add a single file to your versioned source code:
    ./pake/options-myextension.yaml
    You should configure git / svn to ignore the complete ./vendor, ./build/ and ./dist/ directories

- Help. After building the extension my eZ Publish is not working anymore!
    This is a very rare situation. It might happen if
    . you build the extension within a live eZ Publish installation,
    . you do not clean up the build directory after the build, and
    . you regenerate the eZ Publish autoload configuration (eg. by activating or deactivanting an extension)
    What is happening is that the autoload configuration is pointing to php
    classes in files inside the build directory instead of the files in the
    extension itself.
    The fix: just clean up the build dir and regenerate the autoload configuration
