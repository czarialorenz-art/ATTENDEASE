<?php

/*
 | FR-ADM-10: the fixed lists for a student's course, year level and section.
 | Add a course or a section letter here and it shows up in every dropdown,
 | the import checks and the conversion command.
 */
return [

    // Course code => full name. "aliases" are other ways the course may have been typed
    // (matched ignoring case, spaces and punctuation) by the import and the conversion command.
    'courses' => [
        'BSIT' => [
            'name' => 'Bachelor of Science in Information Technology',
            'aliases' => ['BSIT', 'BS IT', 'BS Info Tech', 'Information Technology', 'Info Tech'],
        ],
    ],

    // Year levels 1 to 4 ("1st Year" ... "4th Year").
    'year_levels' => [1, 2, 3, 4],

    // Section letters within a year level, e.g. 4th Year Section B.
    'sections' => ['A', 'B', 'C', 'D'],

];
