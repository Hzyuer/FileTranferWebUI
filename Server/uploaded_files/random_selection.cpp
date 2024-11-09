#include<bits/stdc++.h>
using namespace std;
const int Maxn = 100000;
int a[Maxn];
int main() {
	int n;
	cout<<"请输入需要随机的总数：\n";
	cin>>n;
	
	for(int i=0;i<n;++i)
		a[i]=i+1;
		
	random_shuffle(a,a+n);
	
	int m;
	cout<<"请输入需要的项数：\n";
	cin>>m;
	
	cout<<"以下是随机出来的元素：\n";
	
	for(int i=0;i<m;++i) 
		cout<<a[i]<<" "; 
		 
	return 0;
}
