#!/usr/bin/python
import os
os.system("bin/console cache:clear");
os.system("chmod -R 777 var");
os.system("php bin/console make:migration");
os.system("php bin/console doctrine:migrations:migrate");
print(" ")
print("============== OK ==============")