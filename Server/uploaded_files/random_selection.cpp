#include<bits/stdc++.h>
using namespace std;
const int Maxn = 100000;
int a[Maxn];
int main() {
	int n;
	cout<<"��������Ҫ�����������\n";
	cin>>n;
	
	for(int i=0;i<n;++i)
		a[i]=i+1;
		
	random_shuffle(a,a+n);
	
	int m;
	cout<<"��������Ҫ��������\n";
	cin>>m;
	
	cout<<"���������������Ԫ�أ�\n";
	
	for(int i=0;i<m;++i) 
		cout<<a[i]<<" "; 
		 
	return 0;
}
