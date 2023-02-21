# PIT-for-LMS
Simple script for creating PIT reports directly from @chilek/lms software (thanks @chilek !) for WiFi only operators

Installation
- Clone repository somewhere 
- Copy pit.php file into the LMSDIR/modules

Design Assumptions
- Script is based on newest LMS version (git-LMS_27) and was NOT tested with ANY other version (but might work for You)
- All hub/nodes (węzły) are correctly entered and have all data required by PIT report (TERYT, GPS COORDS in correct system and so on)
- -------> All hub/nodes that are required to be included in report HAVE TO contain [PIT] in the begining of their names e.g. [PIT]Something <-------
- All devices creating wireless lines have to have name format "[PIT]Dosył_Something"
    - Devices from the same Hub/Node are not connected

Usage
- Just enter Your lms address follwed by ?m=pit.php e.g.: https://your.lms.address/?m=pit

At the moment script only generates CVS for:
- węzły
- punkty elastyczności
- linie bezprzewodowe

as new reports will be added, new assumptions and requirements may follow
