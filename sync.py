#!/usr/bin/python
import os

curr_path = os.getcwd()
curr_path = curr_path.replace("/payment","")

os.system("rm -rf "+curr_path+"/admin/src/Entity");
os.system("rm -rf "+curr_path+"/merchant/src/Entity");
os.system("rm -rf "+curr_path+"/service/src/Entity");

os.system("rm -rf "+curr_path+"/admin/src/Repository");
os.system("rm -rf "+curr_path+"/merchant/src/Repository");
os.system("rm -rf "+curr_path+"/service/src/Repository");

os.system("cp -r "+curr_path+"/payment/src/Entity "+curr_path+"/admin/src/");
os.system("cp -r "+curr_path+"/payment/src/Entity "+curr_path+"/merchant/src/");
os.system("cp -r "+curr_path+"/payment/src/Entity "+curr_path+"/service/src/");

os.system("cp -r "+curr_path+"/payment/src/Repository "+curr_path+"/admin/src/");
os.system("cp -r "+curr_path+"/payment/src/Repository "+curr_path+"/merchant/src/");
os.system("cp -r "+curr_path+"/payment/src/Repository "+curr_path+"/service/src/");

print(" ")
print("============= sync complete =============")
print(" ")