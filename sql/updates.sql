[2015-03-02]

ALTER TABLE  `Reports` ADD  `LastNotifyTimestamp` INT( 12 ) NOT NULL AFTER  `LastNotifyReportCount` ;
ALTER TABLE  `Reports` ADD  `Status` varchar(255) NOT NULL AFTER  `CustomerIgnored` ;

[2015-02-22]

ALTER TABLE  `Reports` ADD  `Type` VARCHAR( 10 ) NOT NULL AFTER  `Class` ;

[2015-05-06]

ALTER TABLE  `Reports` MODIFY COLUMN  `Domain` VARCHAR(255) ;
ALTER TABLE  `Reports` MODIFY COLUMN  `URI` VARCHAR(255) ;

