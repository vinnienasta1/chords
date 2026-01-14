<?php
class DB {
    private static  = null;

    public static function getConnection() {
        if (self:: === null) {
             = getenv('DB_HOST') ?: 'localhost';
             = getenv('DB_NAME') ?: 'chords';
             = getenv('DB_USER') ?: 'user';
             = getenv('DB_PASS') ?: 'pass';

             = mysql:host={}
